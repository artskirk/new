<?php

namespace Datto\App\Console\Command\Asset\RecoveryPoints;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\App\Security\Constraints\AssetExists;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Refresh all recovery points for a specific asset.
 *
 * @author Marcus Recck <mr@datto.com>
 */
class RefreshRecoveryPointsCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:recoverypoints:refresh';

    /** @var RecoveryPointInfoService */
    private $recoveryPointInfoService;

    public function __construct(
        RecoveryPointInfoService $recoveryPointInfoService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->recoveryPointInfoService = $recoveryPointInfoService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Update all recovery point keys.')
            ->addArgument('asset', InputArgument::OPTIONAL, 'Asset to target.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Refresh recovery point keys for all assets.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $all = $input->getOption('all');
        if ($all) {
            $assets = $this->assetService->getAll();
        } else {
            $assetKey = $input->getArgument('asset');

            $assets = [$this->assetService->get($assetKey)];
        }

        $this->refreshRecoveryPoints($assets);
        return 0;
    }

    /**
     * @param Asset[] $assets
     */
    private function refreshRecoveryPoints(array $assets): void
    {
        foreach ($assets as $asset) {
            $this->logger->setAssetContext($asset->getKeyName());

            // Until replicated assets receive their first offsite point, speedsync doesn't
            // create the zfs dataset which means there are no recovery points to update yet
            if ($asset->getOriginDevice()->isReplicated() && !$asset->hasDatasetAndPoints()) {
                continue;
            }

            try {
                $this->recoveryPointInfoService->refreshKeys($asset);
                $this->logger->debug('AIS0010 Updated recovery points.', ['assetKey' => $asset->getKeyName()]);
            } catch (Throwable $e) {
                $this->logger->error('AIS0013 Failed to update recovery points.', ['assetKey' => $asset->getKeyName(), 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function validateArgs(InputInterface $input): void
    {
        $all = $input->getOption('all');
        if ($all === false) {
            $this->commandValidator->validateValue(
                $input->getArgument('asset'),
                new AssetExists([]),
                'Asset must exist'
            );
        }
    }
}
