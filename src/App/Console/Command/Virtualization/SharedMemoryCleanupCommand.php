<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\Virtualization\SharedMemoryCleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command that cleans up virtualization remnants in the shared memory folder.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class SharedMemoryCleanupCommand extends Command
{
    protected static $defaultName = 'virtualization:memory:cleanup';

    /** @var SharedMemoryCleanupService */
    private $sharedMemoryCleanupService;

    public function __construct(SharedMemoryCleanupService $sharedMemoryCleanupService)
    {
        parent::__construct();

        $this->sharedMemoryCleanupService = $sharedMemoryCleanupService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Cleans up verification remnants in the shared memory directory.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->sharedMemoryCleanupService->cleanupSharedMemory();
        return 0;
    }
}
