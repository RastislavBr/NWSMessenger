<?php

declare(strict_types=1);

namespace RastislavBr\Messenger\DI;

use Nette\DI\CompilerExtension;
use Nette\DI\ServiceDefinition;
use Nette\DI\Statement;
use Nette\PhpGenerator\ClassType;
use RastislavBr\Messenger\Exceptions\InvalidHandlerService;
use RastislavBr\Messenger\Exceptions\MultipleHandlersFound;
use RastislavBr\Messenger\LazyHandlersLocator;
use RastislavBr\Messenger\Tracy\LogToPanelMiddleware;
use RastislavBr\Messenger\Tracy\MessengerPanel;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\Handler\MessageSubscriberInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use ReflectionClass;
use ReflectionException;

/**
 * @author Rastislav Brocka <rastislav.brocka@petitpress.sk>
 */
class MessengerExtension extends CompilerExtension
{
    /**
     * @var string
     */
    private $handlerTag;

    /**
     * @var string
     */
    private $busTag;

    /**
     * @var string
     */
    private $receiverTag;

    /**
     * @var string
     */
    private $handlersLocator;

    private const PANEL_MIDDLEWARE_SERVICE_NAME = '.middleware.panel';
    private const PANEL_SERVICE_NAME = 'panel';

    public function __construct(
        string $handlerTag = 'messenger.message_handler',
        string $busTag = 'messenger.bus',
        string $receiverTag = 'messenger.receiver',
        string $handlersLocator = '.handlersLocator'
    ) {
        $this->handlerTag = $handlerTag;
        $this->busTag = $busTag;
        $this->receiverTag = $receiverTag;
        $this->handlersLocator = $handlersLocator;
    }

    public function loadConfiguration()
    {
        $builder = $this->getContainerBuilder();

        foreach ($this->getConfig()->buses as $busName => $busConfig) {
            assert($busConfig instanceof BusConfig);

            $middleware = [];

            if ($busConfig->panel) {
                $middleware[] = $builder->addDefinition($this->prefix($busName . self::PANEL_MIDDLEWARE_SERVICE_NAME))
                    ->setFactory(LogToPanelMiddleware::class, [$busName]);
            }

            foreach ($busConfig->middleware as $index => $middlewareDefinition) {
                $middleware[] = $builder->addDefinition($this->prefix($busName . '.middleware.' . $index))
                    ->setFactory($middlewareDefinition);
            }

            $handlersLocator = $builder->addDefinition($this->prefix($busName . $this->handlersLocator))
                ->setFactory(LazyHandlersLocator::class);

            $middleware[] = $builder->addDefinition($this->prefix($busName . '.defaultMiddleware'))
                ->setFactory(HandleMessageMiddleware::class, [$handlersLocator, $busConfig->allowNoHandlers]);

            $builder->addDefinition($this->prefix($busName . '.bus'))
                ->setFactory(MessageBus::class, [$middleware]);
        }

        if (!$this->isPanelEnabled()) {
            return;
        }

        $builder->addDefinition($this->prefix(self::PANEL_SERVICE_NAME))
            ->setType(MessengerPanel::class)
            ->setArguments([$this->getContainerBuilder()->findByType(LogToPanelMiddleware::class)]);
    }

    public function beforeCompile(): void
    {
        $config = $this->getConfig();
        $builder = $this->getContainerBuilder();

        foreach ($config->buses as $busName => $busConfig) {
            assert($busConfig instanceof BusConfig);

            $handlers = [];

            foreach ($this->getHandlersForBus($busName) as $serviceName) {
                foreach ($this->getHandledMessageNames($serviceName) as $messageName) {
                    if (!isset($handlers[$messageName])) {
                        $handlers[$messageName] = [];
                    }

                    $alias = $builder->getDefinition($serviceName)->getTag($this->handlerTag);

                    $handlers[$messageName][$serviceName] = $alias['alias'] ?? null;
                }
            }

            if ($busConfig->singleHandlerPerMessage) {
                foreach ($handlers as $messageName => $messageHandlers) {
                    if (count($messageHandlers) > 1) {
                        throw MultipleHandlersFound::fromHandlerClasses(
                            $messageName,
                            array_map([$builder, 'getDefinition'], array_keys($messageHandlers))
                        );
                    }
                }
            }

            $handlersLocator = $this->getContainerBuilder()
                ->getDefinition($this->prefix($busName . self::HANDLERS_LOCATOR_SERVICE_NAME));

            assert($handlersLocator instanceof ServiceDefinition);

            $handlersLocator->setArguments([$handlers]);
        }
    }

    public function afterCompile(ClassType $class): void
    {
        if (!$this->isPanelEnabled()) {
            return;
        }

        $this->enableTracyIntegration($class);
    }

    private function getHandlersForBus(string $busName): array
    {
        $builder = $this->getContainerBuilder();

        /** @var string[] $serviceNames */
        $serviceNames = array_keys(
            array_merge(
                $builder->findByTag($this->handlerTag),
                $builder->findByType(MessageHandlerInterface::class)
            )
        );

        return array_filter(
            $serviceNames,
            static function (string $serviceName) use ($builder, $busName): bool {
                $definition = $builder->getDefinition($serviceName);

                return ($definition->getTag(self::TAG_HANDLER)['bus'] ?? $busName) === $busName;
            }
        );
    }

    private function getHandledMessageNames(string $serviceName): iterable
    {
        $handlerClassName = $this->getContainerBuilder()->getDefinition($serviceName)->getType();
        assert(is_string($handlerClassName));

        $handlerReflection = new ReflectionClass($handlerClassName);

        if ($handlerReflection->implementsInterface(MessageSubscriberInterface::class)) {
            return call_user_func([$handlerClassName, 'getHandledMessages']);
        }

        try {
            $method = $handlerReflection->getMethod('__invoke');
        } catch (ReflectionException $e) {
            throw InvalidHandlerService::missingInvokeMethod($serviceName, $handlerReflection->getName());
        }

        if ($method->getNumberOfRequiredParameters() !== 1) {
            throw InvalidHandlerService::wrongAmountOfArguments($serviceName, $handlerReflection->getName());
        }

        $parameter = $method->getParameters()[0];
        $parameterName = $parameter->getName();
        $type = $parameter->getType();

        if ($type === null) {
            throw InvalidHandlerService::missingArgumentType($serviceName, $handlerClassName, $parameterName);
        }

        if ($type->isBuiltin()) {
            throw InvalidHandlerService::invalidArgumentType($serviceName, $handlerClassName, $parameterName, $type);
        }

        return [$type->getName()];
    }

    private function enableTracyIntegration(ClassType $class): void
    {
        $class->getMethod('initialize')->addBody(
            $this->getContainerBuilder()->formatPhp(
                '?;',
                [
                    new Statement(
                        '@Tracy\Bar::addPanel',
                        [new Statement('@' . $this->prefix(self::PANEL_SERVICE_NAME))]
                    ),
                ]
            )
        );
    }

    private function isPanelEnabled(): bool
    {
        return $this->getContainerBuilder()->findByType(LogToPanelMiddleware::class) !== [];
    }

}
