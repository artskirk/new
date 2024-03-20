<?php

namespace Datto\App\Console\Command\Restore\File;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Restore\File\FileRestoreService;

/**
 * Base command for file restores.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
abstract class AbstractFileRestoreCommand extends AbstractCommand
{
    /** @var FileRestoreService */
    protected $fileRestoreService;

    public function __construct(
        FileRestoreService $fileRestoreService
    ) {
        parent::__construct();

        $this->fileRestoreService = $fileRestoreService;
    }
}
