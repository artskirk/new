<?php

namespace Datto\Verification\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\EncryptionService;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Service\HvConnectionService;
use Datto\Feature\FeatureService;
use Datto\Common\Resource\PosixHelper;
use Datto\Restore\Virtualization\AgentVmManager;
use Datto\System\ResourceMonitor;
use Datto\System\Transaction\TransactionException;
use Datto\Utility\Systemd\Systemctl;
use Datto\Verification\InProgressVerificationRepository;
use Datto\Verification\VerificationCancelManager;
use Datto\Verification\VerificationResultType;
use Exception;
use Throwable;

/**
 * Run pre-verification checks to determine if verifications can be run.
 *
 * This class generates logs in the VER0100 to VER0199 range.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class PreflightChecks extends VerificationStage
{
    const DISABLE_SCREENSHOTS_KEY = 'disableScreenshots';
    const HYPERVISOR_ERROR_KEY = 'hypervisor.error';
    const DEFAULT_MINIMUM_RAM_IN_MB = 3584; // 3.5GB
    const AVAILABLE_RAM_BUFFER_IN_MB = 200;
    const OVERRIDE_RAM_BUFFER_IN_MB = 256;

    private InProgressVerificationRepository $inProgressVerificationRepository;
    private Systemctl $systemctl;
    protected DeviceConfig $deviceConfig;
    protected PosixHelper $posix;
    private FeatureService $featureService;
    private EncryptionService $encryptionService;
    private ResourceMonitor $resourceMonitor;
    private AgentConfigFactory $agentConfigFactory;
    private VerificationCancelManager $verificationCancelManager;
    private AgentVmManager $agentVmManager;

    public function __construct(
        AgentVmManager $agentVmManager,
        InProgressVerificationRepository $inProgressVerificationRepository,
        Systemctl $systemctl,
        DeviceConfig $deviceConfig,
        PosixHelper $posix,
        FeatureService $featureService,
        EncryptionService $encryptionService,
        ResourceMonitor $resourceMonitor,
        AgentConfigFactory $agentConfigFactory,
        VerificationCancelManager $verificationCancelManager
    ) {
        $this->agentVmManager = $agentVmManager;
        $this->inProgressVerificationRepository = $inProgressVerificationRepository;
        $this->systemctl = $systemctl;
        $this->deviceConfig = $deviceConfig;
        $this->posix = $posix;
        $this->featureService = $featureService;
        $this->encryptionService = $encryptionService;
        $this->resourceMonitor = $resourceMonitor;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->verificationCancelManager = $verificationCancelManager;
    }

    public function commit(): void
    {
        try {
            $this->logger->debug('VER0100 Starting the verification process...');

            $agent = $this->context->getAgent();
            $snapshotEpoch = $this->context->getSnapshotEpoch();

            // First, check for unrecoverable errors
            if (empty($snapshotEpoch)) {
                $message = 'Snapshot epoch time is not set.';
                $this->logger->debug('VER0116 ' . $message);
                $this->setResult(VerificationResultType::FAILURE_UNRECOVERABLE(), $message);
                throw new TransactionException('Pre-flight checks failed. Message: ' .
                    $this->getResult()->getErrorMessage());
            }

            if (!$this->checkDeviceCancel($agent)) {
                return;
            }

            if (!$this->checkDeviceUnrecoverable($agent) ||
                !$this->checkAssetUnrecoverable($agent, $snapshotEpoch) ||
                !$this->checkDeviceIntermittent() ||
                !$this->checkAssetIntermittent($agent)
            ) {
                throw new TransactionException('Pre-flight checks failed. Message: ' .
                    $this->getResult()->getErrorMessage());
            }
        } catch (TransactionException $e) {
            // This is to intercept TransactionException and leave the result unaltered.
            throw $e;
        } catch (Throwable $e) {
            $result = VerificationResultType::FAILURE_UNRECOVERABLE();
            $this->setResult($result, $e->getMessage(), $e->getTraceAsString());
            throw $e;
        }

        $this->logger->debug('VER0114 Verification has passed preflight.');
        $this->setResult(VerificationResultType::SUCCESS());
    }

    public function cleanup(): void
    {
        // There are no cleanup actions for this stage.
    }

    /**
     * Perform device checks for unrecoverable errors
     *
     * @param Agent $agent
     * @return bool True if all device checks pass, false otherwise.
     */
    private function checkDeviceUnrecoverable(Agent $agent): bool
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_VERIFICATIONS, null, $agent)) {
            $message = 'Verifications are not supported on this model.';
            $this->logger->debug('VER0103 ' . $message);
            $this->setResult(VerificationResultType::FAILURE_UNRECOVERABLE(), $message);
            return false;
        }

        if ($this->deviceConfig->has('isVirtual') && $this->deviceConfig->has(static::HYPERVISOR_ERROR_KEY)) {
            $error = trim($this->deviceConfig->get(static::HYPERVISOR_ERROR_KEY));
            $message = "Hypervisor configuration has an error: {$error}. Exiting.";
            $this->logger->debug('VER0105 ' . $message);
            $this->setResult(VerificationResultType::FAILURE_UNRECOVERABLE(), $message);
            return false;
        }

        return true;
    }

    /**
     * Perform device checks for intermittent errors
     *
     * @return bool True if all device checks pass, false otherwise.
     */
    private function checkDeviceIntermittent(): bool
    {
        if ($this->context->getConnection()->isLocal() && !$this->hasAvailableRam()) {
            $message = 'Verification cannot start due to low RAM.';
            $this->logger->debug('VER0115 ' . $message);
            $this->setResult(VerificationResultType::FAILURE_INTERMITTENT(), $message);
            return false;
        }

        if (!$this->hasLibvirtConnection()) {
            $message = 'Verification cannot start because a connection to libvirt cannot be established.';
            $this->logger->debug('VER0120 ' . $message);
            $this->setResult(VerificationResultType::FAILURE_INTERMITTENT(), $message);
            return false;
        }

        return true;
    }

    /**
     * Check if the asset meets any cancel criteria and set the manager to cancel the transaction
     */
    private function checkDeviceCancel(Agent $agent): bool
    {
        try {
            $this->systemctl->assertSystemRunning();
        } catch (Exception $e) {
            $this->logger->info(
                'VER0107 Not verifying because device is shutting down.',
                ["errorMessage" => $e->getMessage()]
            );
            $this->verificationCancelManager->cancel($agent);
            return false;
        }

        if ($this->deviceConfig->has(static::DISABLE_SCREENSHOTS_KEY)) {
            $this->logger->info('VER0101 Not verifying because disableScreenshots is true.');
            $this->verificationCancelManager->cancel($agent);
            return false;
        }

        if ($this->isVerificationInProgress()) {
            $this->logger->info('VER0104 Verification is running... exiting.');
            $this->verificationCancelManager->cancel($agent);
            return false;
        }
        return true;
    }

    /**
     * Perform agent checks for unrecoverable errors
     *
     * @param Agent $agent Agent or Share that was snapshotted
     * @param int $snapshotEpoch Epoch time of the snapshot
     * @return bool True if all agent checks pass, false otherwise.
     */
    private function checkAssetUnrecoverable(Agent $agent, int $snapshotEpoch): bool
    {
        if ($agent->getOriginDevice()->isReplicated()) {
            $message = 'Verification cannot be run on replicated assets.';
            $this->logger->debug('VER0113 ' . $message);
            $this->setResult(VerificationResultType::FAILURE_UNRECOVERABLE(), $message);
            return false;
        }

        if (!$agent->getLocal()->getRecoveryPoints()->exists($snapshotEpoch)) {
            $message = "The recovery point no longer exists.";
            $this->logger->debug('VER0111 ' . $message);
            $this->setResult(VerificationResultType::FAILURE_UNRECOVERABLE(), $message);
            return false;
        }

        if (!$agent->getScreenshot()->isSupported()) {
            $message = 'Verification not supported for agent\'s operating system or device.';
            $this->logger->debug('VER0118 ' . $message);
            $this->setResult(VerificationResultType::FAILURE_UNRECOVERABLE(), $message);
            return false;
        }

        if ($this->agentVmManager->ideExceedsVolumeLimit($agent->getKeyName(), $snapshotEpoch)) {
            $message = 'Verification failed. IDE storage controllers support 4 volumes maximum, but your backup' .
                ' has more than 4 volumes. Please select a different default storage controller on the' .
                ' \'Configure System Settings\' page.';
            $this->logger->info('VER0119 ' . $message);
            $this->setResult(VerificationResultType::FAILURE_UNRECOVERABLE(), $message);
            return false;
        }

        return true;
    }

    /**
     * Perform agent checks for intermittent errors
     *
     * @param Agent $agent Agent or Share that was snapshotted
     * @return bool True if all agent checks pass, false otherwise.
     */
    private function checkAssetIntermittent(Agent $agent): bool
    {
        $agentName = $agent->getKeyName();
        if ($this->encryptionService->isEncrypted($agentName) && !$this->encryptionService->isAgentMasterKeyLoaded($agentName)) {
            $message = "Encrypted but no key on hand.";
            $this->logger->debug('VER0109 ' . $message);
            $this->setResult(VerificationResultType::FAILURE_INTERMITTENT(), $message);
            return false;
        }

        $agentConfig = $this->agentConfigFactory->create($agent->getKeyName());
        if ($agentConfig->has(static::DISABLE_SCREENSHOTS_KEY)) {
            $message = 'Verifications are disabled for this agent.';
            $this->logger->debug('VER0112 ' . $message);
            $this->setResult(VerificationResultType::FAILURE_INTERMITTENT(), $message);
            return false;
        }

        return true;
    }

    /**
     * Determine if there is enough available ram for verification.
     *
     * @return bool True if there is enough available ram.
     */
    private function hasAvailableRam(): bool
    {
        // Check that we have enough free memory to perform a verification
        $safeRam = $this->resourceMonitor->getRamFreeMiB() - static::AVAILABLE_RAM_BUFFER_IN_MB;

        $screenshotOverride = $this->context->getScreenshotOverride();
        if (!empty($screenshotOverride->getOverrideRamInMiB())) {
            $ramCheck = $screenshotOverride->getOverrideRamInMiB() + static::OVERRIDE_RAM_BUFFER_IN_MB;
        } else {
            $ramCheck = static::DEFAULT_MINIMUM_RAM_IN_MB;
        }

        return $safeRam >= $ramCheck;
    }

    /**
     * Determine if there is a good connection to the libvirt process.
     *
     * @return bool True if there is a good connection to libvirt, false otherwise
     */
    private function hasLibvirtConnection(): bool
    {
        /** @var AbstractLibvirtConnection $connection */
        $connection = $this->context->getConnection();
        try {
            $libvirt = $connection->getLibvirt();
            return (bool)$libvirt->getConnectionHypervisor();
        } catch (Exception $e) {
            if ($this->context->getConnection()->getType() === ConnectionType::LIBVIRT_HV()
                && HvConnectionService::isInvalidCertificateError($e)) {
                $this->logger->error('VER0124 Hyper-V certificate error', ['exception' => $e]);
            } else {
                $this->logger->error('VER0125 Libvirt connection error', ['exception' => $e]);
            }
            return false;
        }
    }

    /**
     * See if verification is in progress.
     *
     * @return bool True if currently in progress, false otherwise.
     */
    private function isVerificationInProgress(): bool
    {
        $inProgress = $this->inProgressVerificationRepository->find();

        if (!$inProgress) {
            return false;
        }

        return $this->posix->isProcessRunning($inProgress->getPid());
    }
}
