<?php

namespace Datto\App\Console\Command\System\Update\Window;

use Datto\System\Update\UpdateWindowService;
use Symfony\Component\Console\Command\Command;

/**
 * Shared functionality between update-window commands.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
abstract class AbstractWindowCommand extends Command
{
    /** @var UpdateWindowService */
    protected $updateWindowService;

    public function __construct(UpdateWindowService $updateWindowService)
    {
        parent::__construct();

        $this->updateWindowService = $updateWindowService;
    }
}
