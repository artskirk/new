<?php

namespace Datto\App\Console\Command\System;

use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Datto\ZFS\ZfsDatasetService;
use Datto\ZFS\ZpoolService;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Repair zfs dataset mounts
 *
 * @author Andrew Cope <acope@datto.com>
 */
class ZfsRepairCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'system:zfs:repair';

    const FORCE_OPTION = '--force';
    const NO_CONFIRM_OPTION = '--no-confirmation';
    // ZFS_REPAIR_GUARD used to insure ZFS Repair command runs at most one time.  Use --force to override
    const ZFS_REPAIR_GUARD = '/dev/shm/zfsRepairGuard';

    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /** @var ZpoolService */
    private $zpoolService;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        ZfsDatasetService $zfsDatasetService,
        ZpoolService $zpoolService,
        Filesystem $filesystem
    ) {
        parent::__construct();

        $this->zfsDatasetService = $zfsDatasetService;
        $this->zpoolService = $zpoolService;
        $this->filesystem = $filesystem;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setDescription('Cleans up the homePool dataset folders and mounts the datasets')
            ->addOption(
                self::FORCE_OPTION,
                null,
                InputOption::VALUE_OPTIONAL,
                'Force a clean of the homePool datasets.'
            )
            ->addOption(
                self::NO_CONFIRM_OPTION,
                null,
                InputOption::VALUE_NONE,
                'Do not ask for confirmation. It is not advised to use this option!'
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $noConfirmation = $input->hasParameterOption(array(self::NO_CONFIRM_OPTION));
        $isForcedCleanup = $input->hasParameterOption(array(self::FORCE_OPTION));

        if (!$isForcedCleanup && $this->hasZfsRepairRun()) {
            $this->logger->info('ZRC0001 Force cleanup was not specified and a repair has already run.');

            return 0;
        }
        $this->setZfsRepairGuard();

        if (!$noConfirmation) {
            $helper = $this->getHelper('question');
            $confirmation = new ConfirmationQuestion(
                'Ensure that no backups are running and that backups are paused. Otherwise, data may be lost! Are you sure you wish to repair ZFS? [y/N] ',
                false,
                '/^y/i'
            );

            if (!$helper->ask($input, $output, $confirmation)) {
                return 1;
            }
        }

        $isImported = $this->zpoolService->isImported('homePool');
        if (!$isImported) {
            $this->zpoolService->import('homePool');
        }

        if ($isForcedCleanup || !$this->zfsDatasetService->areAllDatasetsMounted()) {
            $this->zfsDatasetService->repair();
        }

        return 0;
    }

    /**
     * Returns true if zfs repair has run since last reboot.
     *
     * @return bool
     */
    private function hasZfsRepairRun(): bool
    {
        return ($this->filesystem->exists(self::ZFS_REPAIR_GUARD));
    }

    /**
     * Sets a file guard to indicate that this command has been run once.
     */
    private function setZfsRepairGuard(): void
    {
        $this->filesystem->touch(self::ZFS_REPAIR_GUARD);
    }
}
