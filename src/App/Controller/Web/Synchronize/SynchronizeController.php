<?php

namespace Datto\App\Controller\Web\Synchronize;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Cloud\SpeedSync;
use Datto\Common\Resource\Filesystem;
use Datto\Feature\FeatureService;
use Datto\Networking\BandwidthUsageService;
use Datto\Resource\DateTimeService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\System\Storage\LocalStorageUsageService;
use Datto\Utility\ByteUnit;
use Exception;

/**
 * Controller for the Synchronize page
 *
 * @author Peter Salu <psalu@datto.com>
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class SynchronizeController extends AbstractBaseController
{
    private LocalStorageUsageService $localStorageUsageService;
    private BandwidthUsageService $bandwidthUsageService;
    private AssetService $assetService;
    private FeatureService $featureService;

    public function __construct(
        NetworkService $networkService,
        LocalStorageUsageService $localStorageUsageService,
        BandwidthUsageService $bandwidthUsageService,
        AssetService $assetService,
        FeatureService $featureService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->localStorageUsageService = $localStorageUsageService;
        $this->bandwidthUsageService = $bandwidthUsageService;
        $this->assetService = $assetService;
        $this->featureService = $featureService;
    }

    /**
     * Returns the synchronize page
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $parameters = [
            'isRoundtripDisabled' => $this->isRoundtripDisabled(),
            'isRoundtripNextGenNasEnabled' => $this->isRoundtripNextGenNasEnabled(),
            'localStorage' => $this->getLocalStorage(),
            'bandwidthUsage' => $this->getBandwidthUsage()
        ];

        return $this->render('Synchronize/index.html.twig', $parameters);
    }

    /**
     * Get data used for rendering the local storage chart
     *
     * @return array local storage chart parameters
     */
    private function getLocalStorage()
    {
        $usageData = $this->localStorageUsageService->getSpaceUsedByAssets();
        $localStorageAssets = [];

        foreach ($usageData as $assetKeyName => $spaceUsed) {
            $spaceUsed = is_numeric($spaceUsed) && $spaceUsed >= 0 ? $spaceUsed : 0;
            $asset = $this->assetService->get($assetKeyName);
            $localStorageAssets[] = [
                'spaceUsed' => round(ByteUnit::BYTE()->toGiB($spaceUsed), 2),
                'keyName' => $assetKeyName,
                'displayName' => $asset->getDisplayName(),
                'isShare' => $asset->isType(AssetType::SHARE),
                'isInboundPeerReplicated' => $asset->getOriginDevice()->isReplicated(),
                'isOutboundPeerReplicated' => SpeedSync::isPeerReplicationTarget($asset->getOffsiteTarget()),
                'assetType' => $asset->getType(),
            ];
        }

        $sortByUsed = function ($a, $b) {
            return $b['spaceUsed'] <=> $a['spaceUsed'];
        };
        usort($localStorageAssets, $sortByUsed);

        $freeSpace = $this->localStorageUsageService->getFreeSpace();
        $offsiteTransferSpace = $this->localStorageUsageService->getOffsiteTransferSpace();
        $localStorage = [
            'assets' => $localStorageAssets,
            'freeSpace' => round(ByteUnit::BYTE()->toGiB($freeSpace), 2),
            'offsiteTransferSpace' => round(ByteUnit::BYTE()->toGiB($offsiteTransferSpace), 2),
        ];

        return $localStorage;
    }

    /**
     * Get bandwidth usage data
     *
     * @return array data for the bandwidth usage chart
     */
    private function getBandwidthUsage()
    {
        try {
            $bandwidthUsagePoints = $this->bandwidthUsageService->getHourlyUsageData();
        } catch (Exception $e) {
            $bandwidthUsagePoints = [];
        }

        $bandwidthUsageData = [];
        foreach ($bandwidthUsagePoints as $point) {
            $bandwidthUsageData[] = [
                'hour' => $point->getPeriodNumber(),
                'transmitRate' => round($point->getTransmitRate() / DateTimeService::SECONDS_PER_HOUR),
            ];
        }

        return $bandwidthUsageData;
    }

    /**
     * @return bool true if roundtrip is disabled, otherwise false
     */
    private function isRoundtripDisabled(): bool
    {
        return !$this->featureService->isSupported(FeatureService::FEATURE_ROUNDTRIP);
    }

    /**
     * @return bool true if nas roundtrip is enabled, otherwise false
     */
    private function isRoundtripNextGenNasEnabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_ROUNDTRIP_NAS);
    }
}
