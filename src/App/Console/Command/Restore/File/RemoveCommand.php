<?php

namespace Datto\App\Console\Command\Restore\File;

use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Implements snapctl command to remove file restores
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class RemoveCommand extends AbstractFileRestoreCommand
{
    protected static $defaultName = 'restore:file:remove';

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_FILE];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Removes a file restore')
            ->addArgument('asset', InputArgument::REQUIRED)
            ->addArgument('snapshot', InputArgument::REQUIRED);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('asset');
        $snapshot = $input->getArgument('snapshot');

        $this->fileRestoreService->remove($assetKey, $snapshot);
        return 0;
    }
}
