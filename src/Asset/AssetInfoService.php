<?php

namespace Datto\Asset;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\IncludedVolumesKeyService;
use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Backup\BackupStatusService;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentStateFactory;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Samba\SambaManager;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Utility\ByteUnit;
use Datto\Common\Utility\Filesystem;
use Datto\Util\NetworkSystem;
use Datto\Verification\Notification\VerificationResults;
use Datto\ZFS\ZfsDataset;
use Datto\ZFS\ZfsDatasetService;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Responsible for collecting asset information to be sent to device-web.
 * This is not the best name for this class as it includes information for all assets and other datasets such
 * as configBackup.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class AssetInfoService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const CLONE_BASE = '/homePool';
    const KEY_BASE = '/datto/config/keys/';
    const STORAGE_BASE = '/home/agents';
    const KEY_SCRIPT_PATH = '/datto/scripts/secretKey.sh';
    const SYSTEM_DATASETS = ['configBackup'];

    private Filesystem $filesystem;
    private RescueAgentService $rescueAgentService;
    private DeviceConfig $deviceConfig;
    private AssetService $assetService;
    private ZfsDatasetService $zfsDatasetService;
    private NetworkSystem $networkSystem;
    private RecoveryPointInfoService $recoveryPointInfoService;
    private AgentConfigFactory $agentConfigFactory;
    private SambaManager $sambaManager;
    private AgentStateFactory $agentStateFactory;
    private ScreenshotFileRepository $screenshotFileRepository;
    private IncludedVolumesKeyService $includedVolumesKeyService;

    public function __construct(
        Filesystem $filesystem,
        RescueAgentService $rescueAgentService,
        DeviceConfig $deviceConfig,
        AgentConfigFactory $agentConfigFactory,
        AssetService $assetService,
        ZfsDatasetService $zfsDatasetService,
        NetworkSystem $networkSystem,
        RecoveryPointInfoService $recoveryPointInfoService,
        SambaManager $sambaManager,
        AgentStateFactory $agentStateFactory,
        ScreenshotFileRepository $screenshotFileRepository,
        IncludedVolumesKeyService $includedVolumesKeyService
    ) {
        $this->filesystem = $filesystem;
        $this->rescueAgentService = $rescueAgentService;
        $this->deviceConfig = $deviceConfig;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->assetService = $assetService;
        $this->zfsDatasetService = $zfsDatasetService;
        $this->networkSystem = $networkSystem;
        $this->recoveryPointInfoService = $recoveryPointInfoService;
        $this->sambaManager = $sambaManager;
        $this->agentStateFactory = $agentStateFactory;
        $this->screenshotFileRepository = $screenshotFileRepository;
        $this->includedVolumesKeyService = $includedVolumesKeyService;
    }

    /**
     * Get the asset info from the current state of the device and asset
     *
     * @param string|null $assetKey If supplied only fetch the asset info for one dataset
     * @return array
     */
    public function getAssetInfoFromDevice(string $assetKey = null): array
    {
        $assetInfo = [];

        if ($assetKey !== null) {
            $datasets = [$this->getDataset($assetKey)];
        } else {
            $datasets = $this->getDatasets();
        }

        foreach ($datasets as $dataset) {
            $info = [];
            try {
                $this->fillAssetInfo($info, $dataset);
            } catch (Throwable $e) {
                $this->logger->warning("AIS0012 Error filling asset info for dataset", [
                    'datasetName' => $dataset->getName(),
                    'exception' => $e
                ]);
            }

            $assetInfo[$dataset->getName()] = $info;
        }

        return $assetInfo;
    }

    /**
     * Fill info array for a dataset
     *
     * @param array $info
     * @param ZfsDataset $dataset
     */
    private function fillAssetInfo(array &$info, ZfsDataset $dataset): void
    {
        $fullDatasetName = $dataset->getName();
        $shortDatasetName = preg_replace("~homePool/home/(agents/)?~", "", $fullDatasetName);
        $isAgent = strpos(" " . $fullDatasetName, "agents/") > 0;
        $isSystemDataset = in_array($shortDatasetName, self::SYSTEM_DATASETS);
        $agentConfig = $this->agentConfigFactory->create($shortDatasetName);

        // default values
        $info['uuid'] = $dataset->getUuid();
        $info['originDeviceID'] = $this->deviceConfig->getDeviceId();
        $info['name'] = $shortDatasetName;
        $info['paused'] = false;
        $info['archived'] = false;
        $info['displayName'] = $shortDatasetName;
        $info['fqdn'] = null;
        $info['needsReboot'] = false;
        $info['wantsReboot'] = false;
        $info['localIP'] = null;
        $info['backupState'] = null;

        // zfs values
        $info['zfsPath'] = $dataset->getName();
        $info['used'] = round(ByteUnit::BYTE()->toMiB($dataset->getUsedSpace()));
        $info['usedBySnap'] = round(ByteUnit::BYTE()->toMiB($dataset->getUsedSpaceBySnapshots()));
        $info['compressRatio'] = $dataset->getCompressionRatio();

        // get raw serialized data
        $rawAgentInfo = [];
        if ($agentConfig->has('agentInfo')) {
            $rawAgentInfo = unserialize($agentConfig->get('agentInfo'), ['allowed_classes' => false]);
        }

        // attempt to fetch asset instance
        $asset = null;
        try {
            $asset = $this->assetService->exists($shortDatasetName)
                ? $this->assetService->get($shortDatasetName)
                : null;
        } catch (Throwable $e) {
            $this->logger->warning("AIS0014 Failed to load asset", [
                'datasetName' => $shortDatasetName,
                'exception' => $e
            ]);
        }

        // fill info related to an asset
        if (!empty($asset)) {
            $agentState = $this->agentStateFactory->create($asset->getKeyName());

            $info['uuid'] = $asset->getUuid();
            $info['originDeviceID'] = $asset->getOriginDevice()->getDeviceId();
            $info['paused'] = $asset->getLocal()->isPaused();
            $info['pauseUntil'] = $asset->getLocal()->getPauseUntil();
            $info['pauseWhileMetered'] = $asset->getLocal()->isPauseWhileMetered();
            $info['maxBandwidthInBits'] = $asset->getLocal()->getMaximumBandwidthInBits();
            $info['maxThrottledBandwidthInBits'] = $asset->getLocal()->getMaximumThrottledBandwidthInBits();
            $info['archived'] = $asset->getLocal()->isArchived();
            $info['displayName'] = $asset->getDisplayName();

            $lastVolumeValidationCheck = $agentState->get('lastVolumeValidationCheck', null);
            $info['lastVolumeValidationCheck'] = $lastVolumeValidationCheck;
            $info['lastVolumeValidationResult'] = $lastVolumeValidationCheck !== null ?
                $agentState->get('lastVolumeValidationResult', false) : null;
            $info['isMigrationInProgress'] = $asset->getLocal()->isMigrationInProgress();

            // Mac agents can have encoding issues with this field
            if ($asset->isType(AssetType::MAC_AGENT)) {
                $info['displayName'] = $this->toAscii($asset->getDisplayName());
            }
        }

        // fill info specific to dataset use
        if ($isSystemDataset) {
            $this->fillSystemDatasetInfo($info);
        } elseif ($isAgent) {
            /** @var Agent $agent */
            $agent = !empty($asset) && $asset->isType(AssetType::AGENT) ? $asset : null;
            $this->fillAgentInfo($info, $shortDatasetName, $rawAgentInfo, $agent);
        } else {
            $this->fillShareInfo($info, $shortDatasetName, $rawAgentInfo);
        }

        $this->fillSnapshotInfo($info, $shortDatasetName, $fullDatasetName, $asset);
    }

    /**
     * @param string|null $fqdn
     * @return string|null
     */
    private function resolveFqdn(string $fqdn = null)
    {
        if (empty($fqdn)) {
            return null;
        }

        if (filter_var($fqdn, FILTER_VALIDATE_IP)) {
            return $fqdn;
        }

        $localIpAddress = $this->networkSystem->getHostByName($fqdn);
        if (!filter_var($localIpAddress, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $localIpAddress;
    }

    /**
     * Get the list of zfs datasets under homePool/home, excluding "homePool/home/agents"
     *
     * @return ZfsDataset[] List os zfs datasets
     */
    private function getDatasets(): array
    {
        $datasets = $this->zfsDatasetService->getAllDatasets();

        $filteredDatasets = [];
        foreach ($datasets as $dataset) {
            $name = $dataset->getName();
            if (strpos($name, "homePool/home/") !== false &&
                $name !== 'homePool/home/agents') {
                $filteredDatasets[] = $dataset;
            }
        }

        return $filteredDatasets;
    }

    /**
     * @param string $assetKey
     * @return ZfsDataset
     */
    private function getDataset(string $assetKey): ZfsDataset
    {
        $asset = $this->assetService->get($assetKey);

        return $this->zfsDatasetService->getDataset($asset->getDataset()->getZfsPath());
    }

    /**
     * Get the agent specific info
     *
     * @param array $info
     * @param string $shortDatasetName
     * @param array $rawAgentInfo
     * @param Agent|null $agent
     */
    private function fillAgentInfo(array &$info, string $shortDatasetName, array $rawAgentInfo, Agent $agent = null): void
    {
        $agentConfig = $this->agentConfigFactory->create($shortDatasetName);
        $agentState = $this->agentStateFactory->create($shortDatasetName);

        $info['type'] = $agentConfig->isRescueAgent() ? DatasetPurpose::RESCUE_AGENT : DatasetPurpose::AGENT;
        $info['needsReboot'] = $agentState->has('needsReboot');
        $info['wantsReboot'] = $agentState->has('wantsReboot');
        $info['os'] = $rawAgentInfo['os'] ?? '';
        $info['hostname'] = $rawAgentInfo['hostname'] ?? '';

        // Mac agents can have encoding issues with this field
        if (!empty($agent) && $agent->isType(AssetType::MAC_AGENT)) {
            $info['hostname'] = $this->toAscii($info['hostname']);
        }

        $this->fillAgentDatasetSizeInfo($info, $shortDatasetName, $rawAgentInfo);
        $this->fillAgentScreenshotInfo($info, $shortDatasetName);
        $this->fillAgentErrorInfo($info, $shortDatasetName);

        if (!empty($agent)) {
            $info['fqdn'] = $agent->getFullyQualifiedDomainName();
            $info['localIP'] = $this->resolveFqdn($agent->getFullyQualifiedDomainName());
            $info['lastCheckin'] = $agent->getLocal()->getLastCheckin();
            $this->fillAgentTypeInfo($info, $rawAgentInfo, $agent);
            try {
                // NOTE: this ends up in asset.backupState and should probably be removed.
                // backupState is a transient value and since we only send to cloud every 10 min, it isn't very useful
                $checkIfProcessAlive = !$agent->isType(AssetType::AGENT) || !$agent->isDirectToCloudAgent();
                $backupStatusService = new BackupStatusService($agent->getKeyName(), $this->logger);
                $info['backupState'] = $backupStatusService->get($checkIfProcessAlive)->getState();
            } catch (Throwable $e) {
                $this->logger->warning("AIS0011 Could not determine backup status", [
                    'exception' => $e
                ]);
            }
        }
    }

    /**
     * Get the agent's type info
     *
     * @param array $info
     * @param array $rawAgentInfo
     * @param Agent $agent
     */
    private function fillAgentTypeInfo(array &$info, array $rawAgentInfo, Agent $agent): void
    {
        $platform = $agent->getPlatform();
        $info['agentType'] = $platform->value();

        if ($platform->isAgentless()) {
            $info['version'] = $rawAgentInfo['apiVersion'];
        } elseif ($platform === AgentPlatform::SHADOWSNAP()) {
            $info['version'] = $rawAgentInfo['apiVersion'];
            $info['serial'] = $rawAgentInfo['agentSerialNumber'] ?? '';
        } else {
            $info['version'] = $rawAgentInfo['agentVersion'] ?? 0; // Report zero if direct-to-cloud
            $info['serial'] = '';
        }
    }

    /**
     * Get the agent's dataset size info
     *
     * @param array $info
     * @param string $shortDatasetName
     * @param array $rawAgentInfo
     */
    private function fillAgentDatasetSizeInfo(array &$info, string $shortDatasetName, array $rawAgentInfo): void
    {
        $info['agentSize'] = 0;
        $info['agentFreeSpace'] = 0;
        $info['agentUsedSpace'] = 0;

        $agentConfig = $this->agentConfigFactory->create($shortDatasetName);

        //get all the used space for drives being backed up
        if ($agentConfig->has('include')) {
            $includedGuids = $this->includedVolumesKeyService->loadFromKey($shortDatasetName)->getIncludedList();
            foreach ($includedGuids as $includedGuid) {
                if (isset($rawAgentInfo['volumes'][$includedGuid])) {
                    $info['agentUsedSpace'] += $rawAgentInfo['volumes'][$includedGuid]['used'];
                } elseif (is_array($rawAgentInfo['volumes'])) {
                    foreach ($rawAgentInfo['volumes'] as $volume) {
                        if ($volume['guid'] === $includedGuid) {
                            $info['agentUsedSpace'] += $volume['used'];
                            break;
                        }
                    }
                }
            }
        }

        if (isset($rawAgentInfo['volumes'])) {
            foreach ($rawAgentInfo['volumes'] as $volume) {
                $info['agentSize'] += $volume['spaceTotal'];
                if (isset($volume['spacefree']) && is_numeric($volume['spacefree'])) {
                    $info['agentFreeSpace'] += $volume['spacefree'];
                } elseif (isset($volume['spaceFree']) && is_numeric($volume['spaceFree'])) {
                    $info['agentFreeSpace'] += $volume['spaceFree'];
                }
            }
        }
    }

    /**
     * Get the agent's screenshot info
     *
     * @param array $info
     * @param string $shortDatasetName
     */
    private function fillAgentScreenshotInfo(array &$info, string $shortDatasetName): void
    {
        $storageBase = self::STORAGE_BASE;

        //Screenshot process failure/success (Not OCR errors)
        if ($this->filesystem->exists("$storageBase/$shortDatasetName/screenshotFailed")) {
            $info['screenshotProcessSuccess'] = false;
            $info['screenshotProcessError'] = trim(
                $this->filesystem->fileGetContents("$storageBase/$shortDatasetName/screenshotFailed")
            );
        } else {
            $info['screenshotProcessSuccess'] = true;
            $info['screenshotProcessError'] = VerificationResults::SCREENSHOT_PROCESS_SUCCESS;
        }

        //Screenshot OCR
        $screenshot = $this->screenshotFileRepository->getLatestByKeyName($shortDatasetName);
        if (!is_null($screenshot)) {
            $screenshotTime = $screenshot->getSnapshotEpoch();
            $info['screenshotTime'] = $screenshotTime;
            $screenshotErrorTextPath = ScreenshotFileRepository::getScreenshotErrorTextPath($shortDatasetName, $screenshotTime);
            $screenshotSuccess = !$this->filesystem->exists($screenshotErrorTextPath);
            $info['screenshotSuccess'] = $screenshotSuccess;
            $info['screenshotFailText'] = $screenshotSuccess ? VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE : trim($this->filesystem->fileGetContents($screenshotErrorTextPath));
        }
    }

    /**
     * Get the agent error info for the given asset
     *
     * @param array $info
     * @param string $shortDatasetName
     */
    private function fillAgentErrorInfo(array &$info, string $shortDatasetName): void
    {
        $agentConfig = $this->agentConfigFactory->create($shortDatasetName);

        $errorData = false;
        if ($agentConfig->has('lastError')) {
            $errorData = unserialize($agentConfig->get("lastError"), ['allowed_classes' => false]);
        }

        if ($errorData != false) {
            if (isset($errorData['errorTime'])) {
                $errorTime = $errorData['errorTime'];
                if (!is_numeric($errorTime)) {
                    $et = strtotime($errorTime);
                } else {
                    $et = $errorTime;
                }

                if ($et < $errorData['time']) {
                    $et = $errorData['time'];
                }

                $info['errorTime'] = $et;
                unset($et);
            }

            $failedVolumesError = "The backup job was unable to complete. One or more volumes may have failed to be backed up";
            $jobNotAssignedError = "Backup failed as Backup job was unable to be assigned";
            if ((strpos($errorData['type'], $failedVolumesError) !== false) ||
                (strpos($errorData['type'], $jobNotAssignedError) !== false)
            ) {
                foreach ($errorData['detail'] as $entry) {
                    if (strpos($entry['message'], '-109') !== false) {
                        continue;
                    } else {
                        $info['lastError'] = $entry['message'];
                        break;
                    }
                }
            } else {
                if ($errorData['type'] != '') {
                    $info['lastError'] = $errorData['type'];
                } else {
                    $info['lastError'] = substr(
                        $errorData['message'],
                        0,
                        strpos($errorData['message'], '<br>')
                    );
                }
            }
        }
    }

    /**
     * Get the share info for a specific share
     *
     * @param array $info
     * @param string $shortDatasetName
     * @param array $rawAgentInfo
     */
    private function fillShareInfo(array &$info, string $shortDatasetName, array $rawAgentInfo): void
    {
        if (empty($rawAgentInfo) && $this->sambaManager->doesShareExist($shortDatasetName)) {
            // these are legacy shares
            $info['type'] = DatasetPurpose::ZFS_SHARE;
        } elseif (AssetType::isType(AssetType::EXTERNAL_NAS_SHARE, $rawAgentInfo)) {
            $info['type'] = DatasetPurpose::EXTERNAL_SHARE;
        } elseif (AssetType::isType(AssetType::ISCSI_SHARE, $rawAgentInfo)) {
            $info['type'] = DatasetPurpose::ISCSI_SHARE;
        } else {
            $info['type'] = DatasetPurpose::NAS_SHARE;
        }
    }

    /**
     * Fill info for a system dataset
     *
     * @param array $info
     */
    private function fillSystemDatasetInfo(array &$info): void
    {
        $info['type'] = DatasetPurpose::SYSTEM;
    }

    /**
     * Get the snapshot info for a given dataset
     *
     * @param array $info
     * @param string $shortDatasetName
     * @param string $fullDatasetName
     * @param Asset|null $asset
     */
    private function fillSnapshotInfo(
        array &$info,
        string $shortDatasetName,
        string $fullDatasetName,
        Asset $asset = null
    ): void {
        if ($asset !== null) {
            $snapshots = $this->recoveryPointInfoService->getLocalSnapshotEpochs($asset);

            if ($asset instanceof Agent && $asset->isRescueAgent()) {
                $snapshots = $this->rescueAgentService->filterNonRescueAgentSnapshots($shortDatasetName, $snapshots);
            }
        } else {
            $zfsSnapshots = $this->zfsDatasetService->getDataset($fullDatasetName)->getSnapshots(false);
            $snapshots = array_map(fn($snap) => $snap->getName(), $zfsSnapshots);
        }

        if (count($snapshots) > 0) {
            $info['snapCount'] = count($snapshots);
            $info['firstSnap'] = $snapshots[0];
            $info['lastSnap'] = end($snapshots);
            $info['verificationInfo'] =
                $asset !== null ? $this->getVerificationInfoForPoint($asset, end($snapshots)) : [];
        } else {
            $info['snapCount'] = 0;
            $info['firstSnap'] = null;
            $info['lastSnap'] = null;
        }
    }

    /**
     * Returns Verification Information for a point
     *
     * @param Asset $asset
     * @param int $snapshotEpoch
     * @return array
     */
    private function getVerificationInfoForPoint(Asset $asset, int $snapshotEpoch)
    {
        try {
            return $this->recoveryPointInfoService
                ->get($asset, $snapshotEpoch, false)
                ->getLocalVerificationResultsAsArray();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Convert problematic strings into ascsi.
     *
     * Mac devices have a default naming scheme like "Chris’s Mac mini" and the mac agent presents that as the hostname.
     * That causes issues with device-web because the apostrophe is unicode \u2019.
     *
     * @param string $string
     * @return string
     */
    private function toAscii(string $string)
    {
        return @iconv('UTF-8', 'ASCII//TRANSLIT', $string) ?: $string;
    }
}
