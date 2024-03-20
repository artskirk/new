<?php

namespace Datto\Verification;

use Datto\Alert\AlertManager;
use Datto\Asset\Agent\AgentService;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentStateFactory;
use Datto\Connection\ConnectionType;
use Datto\Connection\Service\ConnectionService;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\System\Inspection\Injector\InjectorAdapter;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionFailureType;
use Datto\Util\StringUtil;
use Datto\Verification\Notification\EmailNotification;
use Datto\Verification\Notification\UploadScreenshot;
use Datto\Verification\Notification\VerificationResults;
use Datto\Verification\Stages\AssetReady;
use Datto\Verification\Stages\CleanupVerifications;
use Datto\Verification\Stages\CreateClone;
use Datto\Verification\Stages\HideFilesStage;
use Datto\Verification\Stages\PreflightChecks;
use Datto\Verification\Stages\PrepareVm;
use Datto\Verification\Stages\RunScripts;
use Datto\Verification\Stages\TakeScreenshot;
use Datto\Verification\Stages\VerificationLock;
use Psr\Log\LoggerAwareInterface;

/**
 * Class VerificationFactory
 *
 * This class is responsible for wiring up all of the verification dependencies.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class VerificationFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const SCRIPTS_TIMEOUT_IN_SECS = 300;

    private AgentService $agentService;
    private ConnectionService $connectionService;
    private FeatureService $featureService;
    private VerificationService $verificationService;
    private InjectorAdapter $injectorAdapter;
    private VerificationMonitoringService $verificationMonitoringService;
    private VerificationQueue $verificationQueue;
    private PreflightChecks $preflightChecks;
    private CleanupVerifications $cleanupVerifications;
    private VerificationLock $verificationLock;
    private CreateClone $createClone;
    private HideFilesStage $hideFilesStage;
    private PrepareVm $prepareVm;
    private AssetReady $assetReady;
    private TakeScreenshot $takeScreenshot;
    private RunScripts $runScripts;
    private EmailNotification $emailNotification;
    private UploadScreenshot $uploadScreenshot;
    private CloudAssistedVerificationOffloadService $cloudAssistedVerificationOffloadService;
    private VerificationCancelManager $verificationCancelManager;
    private AgentStateFactory $agentStateFactory;
    private AgentConfigFactory $agentConfigFactory;
    private DateTimeService $dateTimeService;
    private AlertManager $alertManager;

    public function __construct(
        AgentService $agentService,
        ConnectionService $connectionService,
        FeatureService $featureService,
        VerificationService $verificationService,
        InjectorAdapter $injectorAdapter,
        VerificationMonitoringService $verificationMonitoringService,
        VerificationQueue $verificationQueue,
        PreflightChecks $preflightChecks,
        CleanupVerifications $cleanupVerifications,
        VerificationLock $verificationLock,
        CreateClone $createClone,
        HideFilesStage $hideFilesStage,
        PrepareVm $prepareVm,
        AssetReady $assetReady,
        TakeScreenshot $takeScreenshot,
        RunScripts $runScripts,
        EmailNotification $emailNotification,
        UploadScreenshot $uploadScreenshot,
        CloudAssistedVerificationOffloadService $cloudAssistedVerificationOffloadService,
        VerificationCancelManager $verificationCancelManager,
        AgentStateFactory $agentStateFactory,
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService,
        AlertManager $alertManager
    ) {
        $this->agentService = $agentService;
        $this->connectionService = $connectionService;
        $this->featureService = $featureService;
        $this->verificationService = $verificationService;
        $this->injectorAdapter = $injectorAdapter;
        $this->verificationMonitoringService = $verificationMonitoringService;
        $this->verificationQueue = $verificationQueue;
        $this->preflightChecks = $preflightChecks;
        $this->cleanupVerifications = $cleanupVerifications;
        $this->verificationLock = $verificationLock;
        $this->createClone = $createClone;
        $this->hideFilesStage = $hideFilesStage;
        $this->prepareVm = $prepareVm;
        $this->assetReady = $assetReady;
        $this->takeScreenshot = $takeScreenshot;
        $this->runScripts = $runScripts;
        $this->emailNotification = $emailNotification;
        $this->uploadScreenshot = $uploadScreenshot;
        $this->cloudAssistedVerificationOffloadService = $cloudAssistedVerificationOffloadService;
        $this->verificationCancelManager = $verificationCancelManager;
        $this->agentStateFactory = $agentStateFactory;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->dateTimeService = $dateTimeService;
        $this->alertManager = $alertManager;
    }

    /**
     * Construct a verification process
     *
     * @param string $assetKeyName Name of the asset
     * @param integer $snapshotEpoch Epoch time of the snapshot (must exist)
     * @param integer $screenshotWaitTime User-configurable delay (seconds) to wait before taking a screenshot
     * @return VerificationProcess
     */
    public function create($assetKeyName, $snapshotEpoch, $screenshotWaitTime)
    {
        $agent = $this->agentService->get($assetKeyName);
        $this->logger->setAssetContext($agent->getKeyName());

        $cloudResourceReleaseRequired = false;
        $connection = $this->connectionService->find();
        if ($this->featureService->isSupported(FeatureService::FEATURE_CLOUD_ASSISTED_VERIFICATION_OFFLOADS)) {
            // allow primary hv defined connections to be used, else resort back to default dynamic connection provided by device-web
            if ($connection->getType() !== ConnectionType::LIBVIRT_HV()) {
                $connection = $this->cloudAssistedVerificationOffloadService->createConnection($agent);
                $cloudResourceReleaseRequired = true;
            }
        }

        try {
            $readyTimeout = $this->verificationService->getScreenshotTimeout();
            $recoveryPoint = $agent->getLocal()->getRecoveryPoints()->get($snapshotEpoch);

            if ($recoveryPoint === null) {
                // Should only occur if the point was deleted between VerificationRunner's check and now
                $this->logger->error(
                    "VER3002 Recovery point does not exist, aborting verification",
                    [
                        'asset' => $assetKeyName,
                        'snapshot' => $snapshotEpoch
                    ]
                );
                throw new \Exception(
                    "Recovery point $assetKeyName@$snapshotEpoch does not exist, aborting verification"
                );
            }

            $verificationContext = new VerificationContext(
                StringUtil::generateGuid(),
                $agent,
                $connection,
                $snapshotEpoch,
                $readyTimeout,
                $screenshotWaitTime,
                $this->verificationService->getScriptsTimeout($agent),
                $this->verificationService->getScreenshotOverride($agent->getKeyName()),
                $recoveryPoint
            );
            $verificationContext->setCloudResourceReleaseRequired($cloudResourceReleaseRequired);
            $notificationContext = new NotificationContext();

            $verificationTransaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $this->logger, $verificationContext);
            $verificationTransaction
                ->add($this->preflightChecks)
                ->add($this->cleanupVerifications)
                ->add($this->verificationLock)
                ->add($this->createClone)
                ->add($this->hideFilesStage)
                ->add($this->prepareVm)
                ->add($this->assetReady)
                ->add($this->takeScreenshot)
                ->addIf($this->injectorAdapter->hasScripts($agent), $this->runScripts);

            $verificationTransaction->setOnCancelCallback(
                function () use ($agent) {
                    return $this->verificationCancelManager->isCancelling($agent);
                },
                function () use ($agent) {
                    $this->verificationCancelManager->cleanup($agent);
                }
            );

            $verificationResults = new VerificationResults($verificationContext, $notificationContext);

            $notificationTransaction = new Transaction(TransactionFailureType::CONTINUE_ON_FAILURE(), $this->logger, $verificationResults);
            $notificationTransaction
                ->add($this->emailNotification)
                ->add($this->uploadScreenshot);

            return new VerificationProcess(
                $this->agentService,
                $verificationTransaction,
                $verificationContext,
                $notificationTransaction,
                $notificationContext,
                $verificationResults,
                $this->logger,
                $this->verificationMonitoringService,
                $this->verificationQueue,
                $this->cloudAssistedVerificationOffloadService,
                $this->verificationCancelManager,
                $this->agentStateFactory,
                $this->agentConfigFactory,
                $this->dateTimeService,
                $this->alertManager
            );
        } catch (\Throwable $e) {
            if ($cloudResourceReleaseRequired) {
                $this->cloudAssistedVerificationOffloadService->releaseConnection($agent);
            }
            throw $e;
        }
    }
}
