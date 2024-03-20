<?php


namespace Datto\App\Console\Command\Asset\Offsite;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\OffsiteSettings;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to update the offsiteControl keyfile interval value for an asset.
 */
class OffsiteControlConfigureCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:replication:interval';

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ASSETS,
            FeatureService::FEATURE_OFFSITE
        ];
    }

    protected function configure()
    {
        $this
            ->setDescription('Configure offsiteControl interval value of an asset, if it exists.')
            ->addOption('all', 'A', InputOption::VALUE_NONE, 'Runs for all assets')
            ->addOption('asset', null, InputOption::VALUE_OPTIONAL, 'Run for a single asset')
            ->addOption('device', null, InputOption::VALUE_OPTIONAL, 'Run by asset origin device')
            ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Run by asset type')
            ->addArgument('interval', InputArgument::REQUIRED, 'The replication interval to update to');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validate($input);

        $interval = $input->getArgument('interval');
        $assets = $this->getAssets($input);

        foreach ($assets as $asset) {
            $this->logger->setAssetContext($asset->getKeyName());
            $this->logger->info('COS0001 Updating offsite interval.', ['oldInterval' => $asset->getOffsite()->getReplication(), 'newInterval' => $interval]);

            $asset->getOffsite()->setReplication($interval);
            $this->assetService->save($asset);

            $this->logger->info('COS0002 Offsite interval updated.');
        }
        return 0;
    }

    /**
     * throw if invalid args
     * @param InputInterface $input
     */
    private function validate(InputInterface $input): void
    {
        $this->validateAsset($input);

        if (!in_array($input->getArgument('interval'), OffsiteSettings::getReplicationOptions())) {
            throw new InvalidArgumentException(sprintf(
                "Invalid offsite interval specified. Valid intervals are: %s",
                join(', ', OffsiteSettings::getReplicationOptions())
            ));
        }
    }
}
