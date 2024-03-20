<?php

namespace Datto\Verification;

use Datto\Alert\AlertManager;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetRemovalService;
use Datto\Asset\AssetRemovalStatus;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\PosixHelper;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Runner class to handle asset verifications.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class VerificationRunner implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var VerificationQueue */
    private $verificationQueue;

    /** @var VerificationFactory */
    private $verificationFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var AgentService */
    private $agentService;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var FeatureService */
    private $featureService;

    /** @var PosixHelper */
    private $posixHelper;

    /** @var AlertManager */
    private $alertManager;

    /** @var Collector */
    private $collector;

    /** @var InProgressVerificationRepository */
    private $inProgressVerificationRepository;

    private AssetRemovalService $assetRemovalService;

    public function __construct(
        VerificationQueue $verificationQueue,
        VerificationFactory $verificationFactory,
        Filesystem $filesystem,
        AgentService $agentService,
        AgentConfigFactory $agentConfigFactory,
        DeviceConfig $deviceConfig,
        FeatureService $featureService,
        PosixHelper $posixHelper,
        AlertManager $alertManager,
        Collector $collector,
        InProgressVerificationRepository $inProgressVerificationRepository,
        AssetRemovalService $assetRemovalService
    ) {
        $this->verificationQueue = $verificationQueue;
        $this->verificationFactory = $verificationFactory;
        $this->filesystem = $filesystem;
        $this->agentService = $agentService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->deviceConfig = $deviceConfig;
        $this->featureService = $featureService;
        $this->posixHelper = $posixHelper;
        $this->alertManager = $alertManager;
        $this->collector = $collector;
        $this->inProgressVerificationRepository = $inProgressVerificationRepository;
        $this->assetRemovalService = $assetRemovalService;
    }

    /**
     * Run the next verification in the queue immediately, if there is one.
     */
    public function runNextQueuedVerification()
    {
        $this->logger->debug('SCN0970 Starting the verification process...');

        // If this is an Alto, lets fix the folder permissions (ALTO-165)
        if ($this->deviceConfig->has(DeviceConfig::KEY_IS_ALTO)) {
            $this->filesystem->chown('/datto/A-Home', 'aurorauser', true);
            $this->filesystem->chgrp('/datto/A-Home', 'aurorauser', true);
        }

        if ($this->verificationQueue->getCount() === 0) {
            $this->logger->debug('SCN4005 No verifications queued.');
            return;
        }

        if ($this->isVerificationInProgress()) {
            $this->logger->debug('VER0104 Verification is running... exiting.');
            return;
        }

        while (($verificationAgent = $this->verificationQueue->getNext()) !== null) {
            $assetKeyName = $verificationAgent->getAssetName();
            $snapshotEpoch = $verificationAgent->getSnapshotTime();
            $assetConfig = $this->agentConfigFactory->create($assetKeyName);
            $this->logger->setAssetContext($assetKeyName);

            if (!$this->agentService->exists($assetKeyName)) {
                $this->logger->info('SCN4013 Dequeuing verification: the asset no longer exists.');
                $this->verificationQueue->remove($verificationAgent);
                continue;
            }

            $agent = $this->agentService->get($assetKeyName);

            $removalState = $this->assetRemovalService->getAssetRemovalStatus($assetKeyName)->getState();

            if (!$agent->getLocal()->getRecoveryPoints()->exists($snapshotEpoch)) {
                $this->logger->info('SCN4011 Dequeueing verification: the snapshot no longer exists.', ['snapshotEpoch' => $snapshotEpoch]);
                $this->verificationQueue->remove($verificationAgent);
            } elseif ($assetConfig->has('disableScreenshots') ||
                !$this->featureService->isSupported(FeatureService::FEATURE_VERIFICATIONS, null, $agent)) {
                $this->logger->info('SCN4012 Dequeueing verification: verifications are disabled for this agent.', ['snapshotEpoch' => $snapshotEpoch]);
                $this->verificationQueue->remove($verificationAgent);
            } elseif (!in_array($removalState, [AssetRemovalStatus::STATE_NONE, AssetRemovalStatus::STATE_ERROR])) {
                $this->logger->info('SCN4014 Dequeueing verification: verification cancelled, agent is being removed.', ['snapshotEpoch' => $snapshotEpoch]);
                $this->verificationQueue->remove($verificationAgent);
            } else {  // We passed all tests and can continue verification with this verification agent.
                $this->alertManager->clearAlert($assetKeyName, 'SCN0870');  // todo: is this valid?
                $screenshotWaitTime = $agent->getScreenshotVerification()->getWaitTime();
                $this->runVerificationOnVM($assetKeyName, $screenshotWaitTime, $snapshotEpoch, $verificationAgent);
                break;
            }
        }
    }

    /**
     * Run verification on a VM.
     *
     * Takes a screenshot of a VM, defaulting to the latest snapshot. A specific
     * snapshot can be specified as the last parameter. For Windows VMs, it will
     * detect whether the OS has reached the GUI prior to taking the screenshot.
     * The delay is used as a timeout for Linux systems and in case Windows fails
     * to boot.
     *
     * When the process is successful the function saves the lastScreenshotTime if the process
     * is a failure this function saves the error to a screenshotFailed file. The
     * verification process itself handles saving the jpg file and all notifications
     * that need to come out of a successful/failed screenshot.
     *
     * @param string $assetKeyName The name of the virtual machine to take a screenshot of.
     * @param int $screenshotWaitTime The delay/timeout for Linux systems and failing Windows systems.
     * @param int $snapshotEpoch A specific snapshot to take a screenshot of.
     * @param VerificationAsset $verificationAgent Verification asset to screenshot
     */
    private function runVerificationOnVM(
        string $assetKeyName,
        int $screenshotWaitTime,
        int $snapshotEpoch,
        VerificationAsset $verificationAgent
    ) {
        $this->logger->setAssetContext($assetKeyName);
        $this->logger->info('SCN4015 Running verification', ['snapshot' => $snapshotEpoch]);

        try {
            $verificationProcess = $this->verificationFactory->create(
                $assetKeyName,
                $snapshotEpoch,
                $screenshotWaitTime
            );
        } catch (Throwable $t) {
            $this->logger->error('SCN4014 Error creating verification process', ['exception' => $t]);
            $this->collector->increment(Metrics::AGENT_VERIFICATION_PROCESS_CREATION_FAILURE);
            return;
        }

        $verificationProcess->execute($verificationAgent);
    }

    /**
     * Determine if a verification is currently in progress.
     *
     * @return bool
     */
    private function isVerificationInProgress(): bool
    {
        $inProgress = $this->inProgressVerificationRepository->find();

        if (!$inProgress) {
            return false;
        }

        return $this->posixHelper->isProcessRunning($inProgress->getPid());
    }
}
