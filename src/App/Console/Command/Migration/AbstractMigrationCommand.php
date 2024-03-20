<?php

namespace Datto\App\Console\Command\Migration;

use Datto\System\Migration\MigrationService;
use Symfony\Component\Console\Command\Command;

/**
 * Common functionality for migration commands
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
abstract class AbstractMigrationCommand extends Command
{
    /** @var MigrationService */
    protected $migrationService;

    public function __construct(
        MigrationService $migrationService
    ) {
        parent::__construct();

        $this->migrationService = $migrationService;
    }
}
