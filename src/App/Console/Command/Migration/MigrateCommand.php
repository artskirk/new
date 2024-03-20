<?php

namespace Datto\App\Console\Command\Migration;

use Datto\App\Console\Command\AbstractListCommand;

/**
 * Dummy class to display a list of migrate commands
 *
 * @author Mario Rial <mrial@datto.com>
 */
class MigrateCommand extends AbstractListCommand
{
    protected static $defaultName = 'migrate';
}
