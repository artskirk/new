<?php

namespace Datto\App\Console\Command\Agentless;

use Datto\App\Console\Command\AbstractListCommand;

/**
 * List agentless subcommands.
 * @author Peter Geer <pgeer@datto.com>
 * @codeCoverageIgnore
 */
class AgentlessCommand extends AbstractListCommand
{
    protected static $defaultName = 'agentless';
}
