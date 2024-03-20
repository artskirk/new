<?php

namespace Datto\App\Console\Command\Asset;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Cloud\SpeedSync;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Render assets.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class AssetListCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:list';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Display all assets')
            ->addOption('keys', null, InputOption::VALUE_NONE, 'Output asset keys')
            ->addOption('asset', null, InputOption::VALUE_REQUIRED, 'Display asset by key')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Display asset by type')
            ->addOption('device', null, InputOption::VALUE_REQUIRED, 'Display asset by origin device')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Display all assets')
            ->addOption('show-origin', '-o', InputOption::VALUE_NONE, 'Display origin info')
            ->addOption(
                'show-replication',
                '-r',
                InputOption::VALUE_NONE,
                'Display replication info.' .
                'The Inbound column indicates the asset\'s replication source--another device or "-" if it originated on this device.' .
                'The Outbound column indicates the asset\'s replication destination--datto cloud, another device, or "-" for no replication.'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assets = $this->getAssets($input);
        $keysOnly = $input->getOption('keys');

        if ($keysOnly) {
            $this->renderAssetKeys($output, $assets);
        } else {
            $this->renderAssetTable($input, $output, $assets);
        }
        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param Asset[] $assets
     */
    private function renderAssetKeys(OutputInterface $output, array $assets): void
    {
        foreach ($assets as $asset) {
            $output->writeln($asset->getKeyName());
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param Asset[] $assets
     */
    private function renderAssetTable(InputInterface $input, OutputInterface $output, array $assets): void
    {
        $table = new Table($output);

        $headers = [
            'Key',
            'Name',
            'Display',
            'Type',
            'Sub-Type'
        ];

        $shouldShowOrigin = $this->shouldShowOrigin($input);

        if ($shouldShowOrigin) {
            $headers = array_merge($headers, ['Origin', 'Reseller']);
        }

        $shouldShowReplication = $input->getOption('show-replication') !== false;
        if ($shouldShowReplication) {
            $headers = array_merge($headers, ['Inbound', 'Outbound']);
        }

        $table->setHeaders($headers);

        foreach ($assets as $asset) {
            $rowData = [
                $asset->getKeyName(),
                $asset->getName(),
                $asset->getDisplayName(),
                $asset->isType(AssetType::AGENT) ? AssetType::AGENT : AssetType::SHARE,
                $asset->getType()
            ];

            if ($shouldShowOrigin) {
                $originDeviceId = $asset->getOriginDevice()->getDeviceId();
                $resellerId = $asset->getOriginDevice()->getResellerId();

                $rowData = array_merge($rowData, [$originDeviceId, $resellerId]);
            }

            if ($shouldShowReplication) {
                $inbound = '-';
                if ($asset->getOriginDevice()->isReplicated()) {
                    // Once we can do secondary offsite of a replicated asset, this should be the source device id
                    // instead of the origin device id
                    $inbound = $asset->getOriginDevice()->getDeviceId();
                }

                $outbound = $asset->getOffsiteTarget();
                if ($outbound === SpeedSync::TARGET_NO_OFFSITE) {
                    $outbound = '-';
                }

                $rowData = array_merge($rowData, [$inbound, $outbound]);
            }

            $table->addRow($rowData);
        }

        $table->render();
    }

    /**
     * Determine if we should display origin device info.
     *
     * @param InputInterface $input
     * @return bool
     */
    private function shouldShowOrigin(InputInterface $input)
    {
        $shouldShow = false;

        $showOrigin = $input->getOption('show-origin') !== false;
        $assetDevice = $input->getOption('device');

        if ($showOrigin || isset($assetDevice)) {
            $shouldShow = true;
        }

        return $shouldShow;
    }
}
