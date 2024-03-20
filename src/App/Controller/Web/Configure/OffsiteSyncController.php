<?php

namespace Datto\App\Controller\Web\Configure;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Config\DeviceConfig;
use Datto\Config\LocalConfig;
use Datto\Common\Utility\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\Utility\Cloud\SpeedSync;

/**
 * Configuration Controller for Offsite Synchronization settings.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class OffsiteSyncController extends AbstractBaseController
{
    private LocalConfig $localConfig;
    private DeviceConfig $deviceConfig;
    private AssetService $assetService;
    private SpeedSync $speedSync;
    private Filesystem $filesystem;

    public function __construct(
        NetworkService $networkService,
        LocalConfig $localConfig,
        DeviceConfig $deviceConfig,
        AssetService $assetService,
        SpeedSync $speedSync,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->localConfig = $localConfig;
        $this->deviceConfig = $deviceConfig;
        $this->assetService = $assetService;
        $this->speedSync = $speedSync;
        $this->filesystem = $filesystem;
    }

    /**
     * Render the main index page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $offsiteSyncSpeed = $this->localConfig->get('txSpeed');
        $isSnapNas = $this->deviceConfig->isSnapNAS();
        $agentsData = $this->getAllAssetsChangedDataInfo();

        return $this->render(
            'Configure/OffsiteSync/index.html.twig',
            [
                'isSnapNas' => $isSnapNas,
                'txSpeed' => $offsiteSyncSpeed / 125,
                'assetsData' => $agentsData,
                'absoluteMax' => count($this->assetService->getAllActiveKeyNames()),
                'currentOffsiteMax' => $this->speedSync->getMaxSyncs()
            ]
        );
    }

    /**
     * Get changed data information about all assets in the system.
     *
     * @return array
     */
    private function getAllAssetsChangedDataInfo()
    {
        $out = [];
        $assets = $this->assetService->getAllActiveLocal();
        $out['dailyTotal'] = 0;
        $out['weeklyTotal'] = 0;

        foreach ($assets as $asset) {
            $out['assets'][$asset->getKeyName()] = $this->getAssetChangedDataInfo($asset);
            $out['dailyTotal'] += $out['assets'][$asset->getKeyName()]['avgDailyChanged'];
            $out['weeklyTotal'] += $out['assets'][$asset->getKeyName()]['avgWeeklyChanged'];
        }

        return $out;
    }

    /**
     * @param Asset $asset
     * @return array
     */
    private function getAssetChangedDataInfo($asset)
    {
        return [
            'hostname' => $asset instanceof Agent ? $asset->getHostname() : $asset->getName(),
            'avgWeeklyChanged' => $this->getAssetAverageDataChange($asset, 'W'),
            'avgDailyChanged' => $this->getAssetAverageDataChange($asset, 'z')
        ];
    }

    /**
     * Gets asset average changed data in the specified time ranged defined by $timeScale
     *
     * @param Asset $asset
     * @param string $timeScale 'z' for day, 'W' for week, more: http://php.net/manual/en/function.date.php
     * @return float
     */
    private function getAssetAverageDataChange(Asset $asset, string $timeScale): float
    {
        $averages = [];
        $transferArray = $this->getTransfersInformation($asset->getKeyName());

        if (!$transferArray) {
            return 0;
        }

        foreach ($transferArray as $time => $size) {
            $timePeriod = date($timeScale, $time);
            $averages[$timePeriod] = isset($averages[$timePeriod]) ? $averages[$timePeriod] + $size : $size;
        }

        return floor(array_sum($averages) / count($averages));
    }

    /**
     * @todo Although this field belongs in the asset class we don't want to add it there.
     * This file is potentially huge, and with the current pattern it would be loaded every time
     * that any Asset is loaded.
     *
     * Returns an array with the size of changed data in every snapshot.
     * Format "timestamp" => size.
     *
     * @param string $assetKey
     * @return array
     */
    private function getTransfersInformation(string $assetKey): array
    {
        $transferFile ="/datto/config/keys/{$assetKey}.transfers";
        $transferLines = [];
        $transfers = [];

        if ($this->filesystem->exists($transferFile)) {
            $transferData = $this->filesystem->fileGetContents($transferFile);
            $transferLines = explode("\n", trim($transferData));
        }

        foreach ($transferLines as $snapshots) {
            $pieces = explode(":", $snapshots);
            if (count($pieces) === 2) {
                $time = $pieces[0];
                $size = $pieces[1];
                $transfers[(int)$time] = (int)$size;
            }
        }

        return $transfers;
    }
}
