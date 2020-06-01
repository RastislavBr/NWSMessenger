<?php

declare(strict_types=1);

namespace RastislavBr\Messenger\Exceptions;

use Exception;
use Nette\DI\ServiceDefinition;

use function array_map;
use function implode;
use function sprintf;

/**
 * @author Rastislav Brocka <rastislav.brocka@petitpress.sk>
 */
final class MultipleHandlersFound extends Exception
{
    public static function fromHandlerClasses(string $messageName, array $handlers): self
    {
        return new self(
            sprintf(
                'There are multiple handlers for message "%s": %s',
                $messageName,
                implode(
                    ', ',
                    array_map(
                        static function (ServiceDefinition $definition): string {
                            return sprintf('Service with type (%s)', $definition->getType());
                        },
                        $handlers
                    )
                )
            )
        );
    }
}
