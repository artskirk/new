<?php

namespace Datto\App\Console\Command\Restore\Iscsi;

use Datto\App\Console\Command\AbstractListCommand;

/**
 * @author Dakota Baber <dbaber@datto.com>
 */
class IscsiCommand extends AbstractListCommand
{
    protected static $defaultName = 'restore:iscsi';
}
