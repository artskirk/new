<?php

namespace Datto\App\Controller\Web\Home;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Cloud\SpeedSync;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Config\LocalConfig;
use Datto\Core\Network\DeviceAddress;
use Datto\Device\Serial;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\LinkService;
use Datto\Service\Networking\NetworkService;
use Datto\System\CheckinService;
use Datto\System\Hardware;
use Datto\System\Storage\LocalStorageUsageService;
use Datto\Utility\ByteUnit;
use Datto\Util\DateTimeZoneService;
use Datto\ZFS\ZfsDatasetService;
use Datto\Log\DeviceLoggerInterface;
use Throwable;
use Datto\Replication\ReplicationDevices;

/**
 * Displays the home "Overview" page.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class HomeController extends AbstractBaseController
{
    private DeviceConfig $deviceConfig;
    private LocalConfig $localConfig;
    private ZfsDatasetService $datasetService;
    private CheckinService $checkinService;
    private LocalStorageUsageService $localStorageUsageService;
    private AgentService $agentService;
    private ShareService $shareService;
    private DateTimeZoneService $dateTimeZoneService;
    private Hardware $hardware;
    private SpeedSync $speedSync;
    private DeviceLoggerInterface $logger;
    private DeviceState $deviceState;
    private DeviceAddress $deviceAddress;
    private LinkService $linkService;
    private Serial $deviceSerial;

    public function __construct(
        NetworkService $networkService,
        CheckinService $checkinService,
        DeviceConfig $deviceConfig,
        LocalConfig $localConfig,
        ZfsDatasetService $datasetService,
        LocalStorageUsageService $localStorageUsageService,
        AgentService $agentService,
        ShareService $shareService,
        DateTimeZoneService $dateTimeZoneService,
        Hardware $hardware,
        SpeedSync $speedSync,
        DeviceLoggerInterface $logger,
        DeviceState $deviceState,
        DeviceAddress $deviceAddress,
        LinkService $linkService,
        Serial $deviceSerial,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->checkinService = $checkinService;
        $this->deviceConfig = $deviceConfig;
        $this->localConfig = $localConfig;
        $this->networkService = $networkService;
        $this->datasetService = $datasetService;
        $this->localStorageUsageService = $localStorageUsageService;
        $this->agentService = $agentService;
        $this->shareService = $shareService;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->hardware = $hardware;
        $this->speedSync = $speedSync;
        $this->logger = $logger;
        $this->deviceState = $deviceState;
        $this->deviceAddress = $deviceAddress;
        $this->linkService = $linkService;
        $this->deviceSerial = $deviceSerial;
    }

    /**
     * Displays the home page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HOME")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $parameters = $this->getParameters();

        return $this->render(
            'Home/index.html.twig',
            $parameters
        );
    }

    /**
     * Gets all twig parameters for the Overview page.
     *
     * @return array
     */
    private function getParameters(): array
    {
        // Device Hostname
        $hostname = $this->networkService->getHostname();

        $isSnapNAS = $this->deviceConfig->isSnapNAS();

        $agents = $this->agentService->getAllActive();
        $shares = $this->shareService->getAll();
        $assets = array_merge($agents, $shares);

        $agentCount = count($agents);
        $shareCount = count($shares);

        $replicatedAgents = array_filter($agents, function (Agent $agent) {
            return $agent->getOriginDevice()->isReplicated();
        });
        $hasReplicatedAgents = count($replicatedAgents) > 0;

        $replicatedShares = array_filter($shares, function (Share $share) {
            return $share->getOriginDevice()->isReplicated();
        });
        $hasReplicatedShares = count($replicatedShares) > 0;

        $timezone = $this->dateTimeZoneService->getTimeZone();
        $timezoneAbbreviation = $this->dateTimeZoneService->abbreviateTimeZone($timezone);

        return [
            'hostname' => $hostname,
            'hasLocalAgentInfo' => !$isSnapNAS,
            'hasLocalShareInfo' => $shareCount > 0,
            'hasReplicatedShareInfo' => $hasReplicatedShares,
            'hasReplicatedAgentInfo' => $hasReplicatedAgents,
            'banners' => $this->getBannerParameters(),
            'deviceInfo' => $this->getDeviceInformationParameters(
                $isSnapNAS,
                $agents,
                $agentCount,
                $shareCount
            ),
            'localStorage' => $this->getLocalStorage($assets),
            'localShareInfo' => $this->getLocalShareInformationParameters($shares),
            'timezoneAbbreviation' => $timezoneAbbreviation
        ];
    }

    /**
     * Gets twig parameters for all banners displayed on the home page.
     *
     * @return array
     */
    private function getBannerParameters()
    {
        $isStaleRestores = $this->checkStaleRestores() && $this->deviceConfig->has('defaultTP');
        if ($isStaleRestores) {
            $staleRestoreDays = (int)$this->deviceConfig->get('defaultTP') / 60 / 60 / 24;
        } else {
            $staleRestoreDays = 0;
        }

        return [
            'isStaleRestores' => $isStaleRestores,
            'staleRestoreDays' => $staleRestoreDays
        ];
    }

    /**
     * Gets twig parameters for the Device Information block on the home page.
     *
     * @param bool $isSnapNAS
     * @param Agent[] $agents List of agents
     * @param int $agentCount
     * @param int $shareCount
     * @return array
     */
    private function getDeviceInformationParameters(
        $isSnapNAS,
        $agents,
        $agentCount,
        $shareCount
    ) {
        $hypervisor = $this->hardware->detectHypervisor();

        $deviceSerial = $this->deviceSerial->get();
        $imageVersion = $this->deviceConfig->getImageVersion();
        $deviceVersion = $this->deviceConfig->getOs2Version();
        $offsiteSyncSpeed = $this->localConfig->get('txSpeed');
        $ipAddress = $this->deviceAddress->getLocalIp();
        $lastCheckinSeconds = $this->checkinService->getSecondsSince();
        $isVirtual = $this->deviceConfig->isVirtual();
        $hypervisorModel = $isVirtual ? $hypervisor->value() : '';
        $linkSpeed = $isVirtual ? '' : $this->getLinkSpeed();
        $freeSpace = $this->getFreeSpaceInGb();
        $offsiteTargets = $this->speedSync->getTargetServerNames();

        return [
            'serial' => $deviceSerial,
            'imageVersion' => $imageVersion,
            'version' => $deviceVersion,
            'offsiteSyncSpeed' => $offsiteSyncSpeed / 125,
            'ipAddress' => $ipAddress,
            'lastCheckinSeconds' => $lastCheckinSeconds,
            'isVirtual' => $isVirtual,
            'linkSpeed' => $linkSpeed,
            'hypervisorModel' => $hypervisorModel,
            'isSnapNAS' => $isSnapNAS,
            'freeSpace' => number_format(round($freeSpace, 2), 2),
            'protected' => $this->getDeviceInfoProtectedParameters($isSnapNAS, $agents),
            'offsiteTargets' => $offsiteTargets,
            'agentCount' => $agentCount,
            'shareCount' => $shareCount
        ];
    }

    /**
     * Gets twig parameters for the "Total Protected Data" field in the
     * "Device Information" block on the home page.
     * As an optimization, if the given agents list is null, the calculations
     * will be skipped and default values will be returned.
     *
     * @param bool $isSnapNAS
     * @param Agent[] $agents List of agents
     * @return array
     */
    private function getDeviceInfoProtectedParameters($isSnapNAS, $agents)
    {
        $isExcessiveProtected = false;
        $protectedFormatted = '';
        $percentUsed = '';
        $capacity = '';

        if (!$isSnapNAS) {
            $protectedBytes = 0;
            foreach ($agents as $agent) {
                foreach ($agent->getVolumes() as $volume) {
                    if ($volume->isIncluded()) {
                        $protectedBytes += $volume->getSpaceUsed();
                    }
                }
            }
            $protectedGiB = round(ByteUnit::BYTE()->toGiB($protectedBytes), 1);
            $protectedFormatted = number_format($protectedGiB, 2);

            $capacity = $this->getStorageCapacityInGb();
            $percentUsed = ($capacity) ? round(($protectedGiB / $capacity) * 100) : "-";   //% used
            //determine if protected data is taking up more than half the total device storage
            if ($protectedGiB > ($capacity / 2)) {
                $isExcessiveProtected = true;
            }
        }

        return [
            'isExcessive' => $isExcessiveProtected,
            'formatted' => $protectedFormatted,
            'percentUsed' => $percentUsed,
            'capacity' => $capacity
        ];
    }

    /**
     * Gets twig parameters for the "Local Share Information" block on the home page.
     *
     * @param Share[] $shares
     * @return array
     */
    private function getLocalShareInformationParameters($shares)
    {
        uasort($shares, function (Share $a, Share $b) {
            return $b->getDataset()->getUsedSize() <=> $a->getDataset()->getUsedSize();
        });

        $shareInfo = [];
        foreach ($shares as $share) {
            $localUsed = ByteUnit::BYTE()->toGiB($share->getDataset()->getUsedSize());
            $usedBySnaps = ByteUnit::BYTE()->toGiB($share->getDataset()->getUsedBySnapshotsSize());
            $lastSnapshot = $share->getLocal()->getRecoveryPoints()->getLast();
            $lastSnapshot = $lastSnapshot ? $lastSnapshot->getEpoch() : null;
            $isReplicated = $share->getOriginDevice()->isReplicated();

            $shareInfo[] = [
                'keyName' => $share->getKeyName(),
                'hostName' => $share->getDisplayName(),
                'lastSnapshot' => $lastSnapshot,
                'isReplicated' => $isReplicated,
                'snapshotStorage' => number_format($usedBySnaps, 2) . ' GB',
                'nasStorage' => number_format($localUsed - $usedBySnaps, 2) . ' GB',
                'totalStorage' => number_format($localUsed, 2) . ' GB',
                'type' => $share->getType(),
                'replication' => $this->getReplicationSource($share)
            ];
        }

        return [
            'shareInfo' => $shareInfo
        ];
    }

    /**
     * Get data used for rendering the local storage chart
     *
     * @param Asset[] $assets
     * @return array local storage chart parameters
     */
    private function getLocalStorage(array $assets)
    {
        $localStorageAssets = [];
        foreach ($assets as $asset) {
            $usedSize = 0;
            if ($asset->getDataset()->exists()) {
                $usedSize = $asset->getDataset()->getUsedSize();
            }
            $offsiteTarget = $asset->getOffsiteTarget();
            $localStorageAssets[] = [
                'spaceUsed' => round(ByteUnit::BYTE()->toGiB($usedSize), 2),
                'keyName' => $asset->getKeyName(),
                'displayName' => $asset->getDisplayName(),
                'isShare' => $asset->isType(AssetType::SHARE),
                'isInboundPeerReplicated' => $asset->getOriginDevice()->isReplicated(),
                'isOutboundPeerReplicated' => SpeedSync::isPeerReplicationTarget($offsiteTarget),
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
     * @return int The LAN link speed in Mbit/s.
     */
    private function getLinkSpeed(): int
    {
        $linkSpeed = -1;

        try {
            // Iterate over the links and get the link speed of whichever holds the default route
            foreach ($this->linkService->getLinks() as $link) {
                if ($link->isDefault()) {
                    $linkSpeed = $link->getLinkSpeed();
                    break;
                }
            }
        } catch (Throwable $throwable) {
            // Log and continue
            $this->logger->error('HME0010 Error while retrieving LAN link speed', ['exception' => $throwable]);
        }

        return $linkSpeed;
    }

    /**
     * @return float
     */
    private function getFreeSpaceInGb()
    {
        try {
            $dataset = $this->datasetService->getDataset(ZfsDatasetService::HOMEPOOL_HOME_DATASET);
            return round(ByteUnit::BYTE()->toGiB($dataset->getAvailableSpace()), 3);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return int
     */
    private function getStorageCapacityInGb()
    {
        try {
            $dataset = $this->datasetService->getDataset(ZfsDatasetService::HOMEPOOL_HOME_DATASET);
            $used = $dataset->getUsedSpace();
            $available = $dataset->getAvailableSpace();

            return round(ByteUnit::BYTE()->toGiB($used + $available));
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function checkStaleRestores()
    {
        if (!$this->deviceConfig->has('defaultTP')) {
            return 0;
        }

        $restores = unserialize($this->deviceConfig->get('UIRestores'), ['allowed_classes' => false]);
        $restores = is_array($restores) ? $restores : [];
        if (count($restores) === 0) {
            return false;
        }

        // Get time period
        $cutoffTime = $this->deviceConfig->get('defaultTP');
        if (empty($cutoffTime)) {
            return false;
        }

        $staleRestoreList = array();
        foreach ($restores as $restore) {
            if (time() - $restore['activationTime'] > $cutoffTime) {
                $staleRestoreList[$restore['agent']] = $restore;
            }
        }
        if (count($staleRestoreList) > 0) {
            return $staleRestoreList;
        }

        return false;
    }

    /**
     * @param Share $share
     * @return array
     */
    private function getReplicationSource(Share $share)
    {
        $shareArray = [];
        if ($share->getOriginDevice()->isReplicated()) {
            $replicationDevices = ReplicationDevices::createInboundReplicationDevices();
            $this->deviceState->loadRecord($replicationDevices);
            $inboundDevice = $replicationDevices->getDevice($share->getOriginDevice()->getDeviceId());
            if ($inboundDevice) {
                return $inboundDevice->toArray();
            }
        }
        return $shareArray;
    }
}
