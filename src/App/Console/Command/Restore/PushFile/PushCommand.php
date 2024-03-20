<?php

namespace Datto\App\Console\Command\Restore\PushFile;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Restore\PushFile\PushFileRestoreService;
use Datto\Restore\PushFile\PushFileRestoreType;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for push file restores.
 *
 * @author Ryan Mack <rmack@datto.com>
 */
class PushCommand extends AbstractCommand
{
    protected static $defaultName = 'restore:file:push';


    private PushFileRestoreService $pushFileRestoreService;

    public function __construct(
        PushFileRestoreService $pushFileRestoreService
    ) {
        parent::__construct();

        $this->pushFileRestoreService = $pushFileRestoreService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_FILE, FeatureService::FEATURE_RESTORE_FILE_PUSH];
    }

    protected function configure()
    {
        $this
            ->setDescription('Push file restore files (must be called on an existing file restore)')
            ->addArgument('asset', InputArgument::REQUIRED, 'Asset with an existing file restore')
            ->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot with an existing file restore')
            ->addArgument('pushFileRestoreType', InputArgument::REQUIRED, 'The type of push file restore desired (e.g. as-archive or in-place)')
            ->addArgument('fileNames', InputArgument::IS_ARRAY, 'Files to restore')
            ->addOption('keep-both', null, InputOption::VALUE_NONE, 'If using an in-place restore, make a copy if the file exists')
            ->addOption('restore-acls', null, InputOption::VALUE_NONE, 'If using an in-place restore, restore files with previous acls')
            ->addOption('destination', null, InputArgument::OPTIONAL, 'The full path for files to be restored to, if different than default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('asset');
        $snapshot = $input->getArgument('snapshot');
        $fileNames = $input->getArgument('fileNames');
        $destination = $input->getOption('destination') ?? '';
        $keepBoth = $input->getOption('keep-both');
        $restoreAcls = $input->getOption('restore-acls');
        $pushFileRestoreType = PushFileRestoreType::memberByValue($input->getArgument('pushFileRestoreType'));

        $this->pushFileRestoreService->pushFiles($assetKey, $snapshot, $pushFileRestoreType, $destination, $keepBoth, $restoreAcls, $fileNames);
        return 0;
    }
}
