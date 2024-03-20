<?php

namespace Datto\App\Console\Command\System;

use Datto\System\Storage\StorageService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Trim filesystems on SSD devices
 *
 * @author Andrew Cope <acope@datto.com>
 */
class FilesystemTrimCommand extends Command
{
    protected static $defaultName = 'system:fstrim';

    /** @var StorageService */
    private $storageService;

    public function __construct(
        StorageService $storageService
    ) {
        parent::__construct();

        $this->storageService = $storageService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setDescription('Trims mounted filesystems on SSD drives only');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->storageService->trimSolidStateDriveFilesystems() ? 0 : 1;
    }
}
