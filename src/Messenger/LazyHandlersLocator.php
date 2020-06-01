<?php

declare(strict_types=1);

namespace RastislavBr\Messenger;

use Nette\DI\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;

/**
 * @author Rastislav Brocka <rastislav.brocka@petitpress.sk>
 */
final class LazyHandlersLocator implements HandlersLocatorInterface
{
    /** @var array<string, array<string, string|null>> message type => [handler service name => alias] */
    private $handlersMap;

    /** @var Container */
    private $container;

    public function __construct(array $handlersMap, Container $container)
    {
        $this->handlersMap = $handlersMap;
        $this->container = $container;
    }

    public function getHandlers(Envelope $envelope): iterable
    {
        $handlers = [];

        foreach ($this->handlersMap[get_class($envelope->getMessage())] ?? [] as $serviceName => $alias) {
            $service = $this->container->getService($serviceName);

            assert(is_callable($service));

            $handlers[] = new HandlerDescriptor($service, ['alias' => $alias ?? $serviceName]);
        }

        return $handlers;
    }
}
