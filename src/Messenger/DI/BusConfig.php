<?php

declare(strict_types=1);

namespace RastislavBr\Messenger\DI;

use Nette\DI\Statement;

/**
 * @author Rastislav Brocka <rastislav.brocka@petitpress.sk>
 */
final class BusConfig
{
    /** @var bool */
    public $allowNoHandlers = false;

    /** @var bool */
    public $singleHandlerPerMessage = false;

    /** @var Statement[] */
    public $middleware = [];

    /** @var bool */
    public $panel = true;
}
