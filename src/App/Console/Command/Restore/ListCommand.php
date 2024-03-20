<?php

namespace Datto\App\Console\Command\Restore;

use Datto\App\Console\Command\AbstractRestoreCommand;
use Datto\Restore\Restore;
use Datto\Restore\RestoreType;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Datto\Asset\Asset;

/**
 * List all active restores.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ListCommand extends AbstractRestoreCommand
{
    protected static $defaultName = 'restore:list';

    const VIRTUAL_MACHINE_RESTORE_TYPES = [RestoreType::ACTIVE_VIRT, RestoreType::RESCUE];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('List restores command')
            ->addOption('asset', null, InputOption::VALUE_REQUIRED, 'List restores for a given asset')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'List restores of a given type')
            ->addOption('show-origin', '-o', InputOption::VALUE_NONE, 'Display origin info')
            ->addOption('device', null, InputOption::VALUE_REQUIRED, 'Display asset by origin device');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assets = $this->getAssets($input);
        $this->renderAssetTable($input, $output, $assets);
        return 0;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param Asset[] $assets
     */
    private function renderAssetTable(InputInterface $input, OutputInterface $output, array $assets): void
    {
        $type = $input->getOption('type');
        $table = new Table($output);
        $headers = [
            'Type',
            'Key',
            'Snapshot',
            'Status'
        ];
        $shouldShowOrigin = $this->shouldShowOrigin($input);

        if ($shouldShowOrigin) {
            $headers = array_merge($headers, ['Device ID', 'Reseller ID', 'Replicated']);
        }

        $restores = $this->restoreService->getAll();
        usort($restores, function (Restore $restore1, Restore $restore2) {
            return $restore2->getPoint() - $restore1->getPoint();
        });

        $table->setHeaders($headers);

        foreach ($assets as $asset) {
            foreach ($restores as $restore) {
                $differentAsset = $asset->getKeyName() !== $restore->getAssetKey();
                $differentType = $type && $restore->getSuffix() !== $type;

                if ($differentAsset || $differentType) {
                    continue;
                }

                $rowData = [
                    $restore->getSuffix(),
                    $restore->getAssetKey(),
                    $restore->getPoint(),
                    $this->getStatus($restore)
                ];
                if ($shouldShowOrigin) {
                    $deviceId = $asset->getOriginDevice()->getDeviceId();
                    $resellerId = $asset->getOriginDevice()->getResellerId();
                    $replicated = $asset->getOriginDevice()->isReplicated() ? 'Yes' : 'No';
                    $rowData = array_merge($rowData, [$deviceId, $resellerId, $replicated]);
                }
                $table->addRow($rowData);
            }
        }
        $table->render();
    }

    /**
     * Gets the human-readable status of the restore.
     *
     * @param Restore $restore
     * @return string
     */
    protected function getStatus(Restore $restore): string
    {
        if (in_array($restore->getSuffix(), self::VIRTUAL_MACHINE_RESTORE_TYPES, true)) {
            return $restore->virtualizationIsRunning() ? 'running' : 'powered off';
        }
        return '-';
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
