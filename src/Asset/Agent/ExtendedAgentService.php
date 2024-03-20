<?php

namespace Datto\Asset\Agent;

use Datto\Alert\AlertCodes;
use Datto\Alert\AlertCodeToKnowledgeBaseMapper;
use Datto\Alert\AlertManager;
use Datto\Asset\Agent\Backup\DiskDrive;
use Datto\Asset\Agent\Backup\Serializer\DiskDriveSerializer;
use Datto\Asset\Agent\Log\LogService;
use Datto\Asset\AssetRemovalService;
use Datto\Asset\AssetRemovalStatus;
use Datto\Asset\AssetType;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\Serializer\LegacyLastErrorSerializer;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentState;
use Datto\Config\AgentStateFactory;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Feature\FeatureService;
use Datto\Malware\RansomwareService;
use Datto\Restore\Virtualization\DuplicateVmException;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Utility\ByteUnit;
use Datto\Virtualization\LocalVirtualizationUnsupportedException;

/**
 * Helper service for gathering agent attributes that are not part of the asset/agent structure.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class ExtendedAgentService
{
    // These strings are used in twig files such as alerts.html.twig
    public const ALERT_TYPE_GENERAL_WARNING = 'general_warning';
    public const ALERT_TYPE_BACKUP = 'backup';
    public const ALERT_TYPE_SCREENSHOT_LOCAL_UNSUPPORTED = 'screenshot_local_unsupported';
    public const ALERT_TYPE_SCREENSHOT_ESX_LEFTOVER = 'screenshot_esx_leftover';
    public const ALERT_TYPE_RANSOMWARE = 'ransomware';
    public const ALERT_TYPE_REMOVE_FAILED = 'remove_failed';
    public const ALERT_TYPE_WANTS_REBOOT = 'wants_reboot';

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var ArchiveService */
    private $archiveService;

    /** @var FeatureService */
    private $featureService;

    /** @var LogService */
    private $logService;

    /** @var ConnectionService */
    private $connectionService;

    /** @var ScreenshotFileRepository */
    private $screenshotFileRepository;

    /** @var AlertManager */
    private $alertManager;

    /** @var AlertCodeToKnowledgeBaseMapper */
    private $alertCodeToKnowledgeBaseMapper;

    /** @var AssetRemovalService */
    private $assetRemovalService;

    /** @var DiskDriveSerializer */
    private $diskDriveSerializer;

    /** @var VolumesService */
    private $volumesService;

    /** @var LegacyLastErrorSerializer */
    private $lastErrorSerializer;

    private AgentStateFactory $agentStateFactory;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        ArchiveService $archiveService,
        FeatureService $featureService,
        LogService $logService,
        ConnectionService $connectionService,
        EncryptionService $encryptionService,
        ScreenshotFileRepository $screenshotFileRepository,
        AlertManager $alertManager,
        AlertCodeToKnowledgeBaseMapper $alertCodeToKnowledgeBaseMapper,
        AssetRemovalService $assetRemovalService,
        DiskDriveSerializer $diskDriveSerializer,
        VolumesService $volumesService,
        LegacyLastErrorSerializer $lastErrorSerializer,
        AgentStateFactory $agentStateFactory
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->archiveService = $archiveService;
        $this->featureService = $featureService;
        $this->logService = $logService;
        $this->connectionService = $connectionService;
        $this->encryptionService = $encryptionService;
        $this->screenshotFileRepository = $screenshotFileRepository;
        $this->alertManager = $alertManager;
        $this->alertCodeToKnowledgeBaseMapper = $alertCodeToKnowledgeBaseMapper;
        $this->assetRemovalService = $assetRemovalService;
        $this->diskDriveSerializer = $diskDriveSerializer;
        $this->volumesService = $volumesService;
        $this->lastErrorSerializer = $lastErrorSerializer;
        $this->agentStateFactory = $agentStateFactory;
    }

    /**
     * Get a set of extended attributes for an agent. These are fields that are not part of the asset structure
     * and have to be fetched via. other services.
     *
     * @param Agent $agent
     * @return ExtendedAgent
     */
    public function getExtended(Agent $agent): ExtendedAgent
    {
        $agentKey = $agent->getKeyName();
        $agentConfig = $this->agentConfigFactory->create($agentKey);

        // Encryption
        $isEncrypted = $agent->getEncryption()->isEnabled();
        $isLocked = $isEncrypted && !$this->encryptionService->isAgentMasterKeyLoaded($agentKey);

        // General
        $removalStatus = $this->assetRemovalService->getAssetRemovalStatus($agent->getKeyName());
        $isRemoving = $removalStatus->getState() === AssetRemovalStatus::STATE_PENDING
            || $removalStatus->getState() === AssetRemovalStatus::STATE_REMOVING;
        $isAgentless = $agent->isType(AssetType::AGENTLESS);
        $ipAddress = $isAgentless ? "" : ($agentConfig->getFullyQualifiedDomainName() ?? "");
        $isArchived = $this->archiveService->isArchived($agentKey);
        $lastBackup = $this->getLastBackupEpoch($agent);
        $logs = $this->logService->getLocalDescending($agent, 20);
        $showScreenshots = $this->featureService->isSupported(
            FeatureService::FEATURE_VERIFICATIONS,
            null,
            $agent
        );

        // Alerts
        $alertsSuppressed = $this->alertManager->isAssetSuppressed($agentKey);

        // Verification
        $lastScreenshotPoint = $agent->getLocal()->getRecoveryPoints()->getMostRecentPointWithScreenshot();
        $hasScripts = false;
        $scriptFailure = false;
        $screenshotSuccess = false;
        $osUpdatePending = false;
        $lastScreenshot = 0;
        if ($lastScreenshotPoint) {
            $lastScreenshot = $lastScreenshotPoint->getEpoch();
            $screenshotSuccess = $lastScreenshotPoint->getVerificationScreenshotResult()->isSuccess();
            $osUpdatePending = $lastScreenshotPoint->getVerificationScreenshotResult()->isOsUpdatePending();
            $hasScripts = $lastScreenshotPoint->getVerificationScriptsResults()->getComplete()
                && count($lastScreenshotPoint->getVerificationScriptsResults()->getOutput()) > 0;
            $scriptFailure = !$lastScreenshotPoint->getVerificationScriptsResults()->isSuccess();
        }

        // Storage
        $protectedVolumes = $this->volumesService->getIncludedVolumesParameters($agent);
        $diskDrives = $agent->getDiskDrives();
        $diskDrivesAsArray = $this->diskDriveSerializer->unserializeAsArray($agentConfig->get('diskDrives'));
        $protectedSize = $this->getProtectedStorageSize($diskDrives, $protectedVolumes);

        return new ExtendedAgent(
            $isAgentless,
            $alertsSuppressed,
            $hasScripts,
            $ipAddress,
            $isArchived,
            $isRemoving,
            $lastBackup,
            $lastScreenshot,
            $logs,
            $isLocked,
            $protectedSize,
            $protectedVolumes,
            $screenshotSuccess,
            $scriptFailure,
            $showScreenshots,
            $osUpdatePending,
            $diskDrivesAsArray
        );
    }

    /**
     * Create alert models for an agent block
     *
     * @todo: structure alerts
     */
    public function getAlerts(string $keyName): array
    {
        $alerts = [];
        $agentConfig = $this->agentConfigFactory->create($keyName);
        $error = $this->lastErrorSerializer->unserialize($agentConfig->get('lastError', null));
        if (!empty($error)) {
            $latestBackup = $this->getSecondToLastBackupTimestamp($agentConfig);
            $latestScreenshotFile = $this->screenshotFileRepository->getLatestByKeyName($keyName);
            $latestScreenshot = $latestScreenshotFile ? $latestScreenshotFile->getSnapshotEpoch() : 0;

            $hasLogs = !empty($error->getLog());
            $isGreaterThanLatestBackup = $latestBackup === 0 || $error->getTime() >= $latestBackup;
            $isGreaterThanLatestScreenshot = AlertCodes::isVerificationCode($error->getCode())
                && $error->getTime() >= $latestScreenshot;

            if ($hasLogs && ($isGreaterThanLatestBackup || $isGreaterThanLatestScreenshot)) {
                // if there is 1 or 0 restore points, show the error regardless of time.
                $alerts[] = $this->generateAgentAlert($error);
            }
        }

        // These two additional alerts are dynamically evaluated instead of being read from lastError key file
        if ($this->isFailingScreenshotDueToLeftoverEsxVm($keyName)) {
            $alertModel['alertType'] = self::ALERT_TYPE_SCREENSHOT_ESX_LEFTOVER;
            $alerts[] = $alertModel;
        } elseif ($this->isFailingScreenshotDueToLocalVirtualizationUnsupported($keyName)) {
            $alertModel['alertType'] = self::ALERT_TYPE_SCREENSHOT_LOCAL_UNSUPPORTED;
            $alerts[] = $alertModel;
        }

        $agentState = $this->agentStateFactory->create($keyName);
        if ($agentState->has('wantsReboot')) {
            $alertModel['alertType'] = self::ALERT_TYPE_WANTS_REBOOT;
            $alerts[] = $alertModel;
        }

        return $alerts;
    }

    /**
     * Returns last agent's backup, 0 if there's no last backup.
     *
     * @param Agent $agent
     * @return int
     */
    private function getLastBackupEpoch(Agent $agent): int
    {
        $recoveryPoints = $agent->getLocal()->getRecoveryPoints();

        if ($recoveryPoints && $recoveryPoints->getLast()) {
            return $recoveryPoints->getLast()->getEpoch();
        } else {
            return 0;
        }
    }

    /**
     * Generates agent alert information based on LastError.
     */
    private function generateAgentAlert(LastErrorAlert $error): array
    {
        if ($error->getCode() === RansomwareService::FOUND_LOG_CODE) {
            $alertType = self::ALERT_TYPE_RANSOMWARE;
        } elseif ($error->getCode() === DestroyAgentService::FAILED_CODE) {
            $alertType = self::ALERT_TYPE_REMOVE_FAILED;
        } else {
            $isUrgentWarning = AlertCodes::checkUrgentWarning($error->getCode());
            $alertType = $isUrgentWarning ? self::ALERT_TYPE_GENERAL_WARNING : self::ALERT_TYPE_BACKUP;
        }
        $message = $error->getMessage();
        $context = $error->getContext();
        if (isset($context['partnerAlertMessage'])) {
            $message = $context['partnerAlertMessage'];
        }

        return [
            'alertType' => $alertType,
            'timestamp' => $error->getTime(),
            'message' => $message,
            'context' => $context,
            'kbSearchQuery' => $this->alertCodeToKnowledgeBaseMapper->getSearchQuery(
                $error->getCode(),
                $error->getMessage()
            )
        ];
    }

    /**
     * Get the timestamp of the second to last successful backup
     */
    private function getSecondToLastBackupTimestamp(AgentConfig $agentConfig): int
    {
        $recoveryPointTimes = explode(PHP_EOL, $agentConfig->getRaw('recoveryPoints', ''));

        // if there is only 1 or less backup, just return 0
        if (count($recoveryPointTimes) < 2) {
            return 0;
        }

        return $recoveryPointTimes[count($recoveryPointTimes) - 2];
    }

    /**
     * Check whether screenshot is failing due to existing ESX VM
     */
    private function isFailingScreenshotDueToLeftoverEsxVm(string $keyName): bool
    {
        $isEsxVerification = $this->connectionService->getPrimary() instanceof EsxConnection;

        $agentState = $this->agentStateFactory->create($keyName);
        $screenshotError = $agentState->get(AgentState::KEY_SCREENSHOT_FAILED, '');

        $domainAlreadyExists = strpos($screenshotError, DuplicateVmException::MESSAGE_PREFIX) !== false;

        return $domainAlreadyExists && $isEsxVerification;
    }

    /**
     * Check whether screenshot is failing because local virtualization unsupported
     */
    private function isFailingScreenshotDueToLocalVirtualizationUnsupported(string $keyName): bool
    {
        $agentState = $this->agentStateFactory->create($keyName);
        $screenshotError = $agentState->get(AgentState::KEY_SCREENSHOT_FAILED, '');

        return strpos($screenshotError, LocalVirtualizationUnsupportedException::MESSAGE_PREFIX) !== false;
    }

    /**
     * Returns the sum of protected GBs by all the agent volumes.
     *
     * @param array $protectedVolumesInfo
     * @return float
     */
    private function getProtectedStorageSize(array $diskInfo, array $protectedVolumesInfo): float
    {
        $sizeByVolume = array_reduce(
            $protectedVolumesInfo,
            function ($sum, array $vol) {
                $protected = $vol['space']['used'] ?? 0;
                return $sum + $protected;
            },
            0
        );

        $sizeByDisk = array_reduce(
            $diskInfo,
            function ($sum, DiskDrive $disk) {
                return $sum + $disk->getCapacityInBytes();
            },
            0
        );

        // Prefer sizeByDisk as only generic agentless currently have this but
        // those also might have some volume data for filesystems guestfs could
        // recognize during paring and added 'volume' entries to its .agentInfo
        return round($sizeByDisk ?: ByteUnit::GIB()->toByte($sizeByVolume));
    }
}
