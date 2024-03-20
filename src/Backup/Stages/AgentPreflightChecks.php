<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentConnectivityService;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\RepairService;
use Datto\Backup\BackupException;
use Datto\Backup\BackupStatusService;
use Datto\Billing\Service;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentStateFactory;
use Datto\Feature\FeatureService;
use Datto\Service\Feature\CloudFeatureService;
use Exception;

/**
 * This backup stage runs checks to determine if a backup should be run.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class AgentPreflightChecks extends BackupStage
{
    private AgentConnectivityService $agentConnectivityService;
    private EncryptionService $encryptionService;
    private Service $billingService;
    private AgentConfigFactory $agentConfigFactory;
    private AgentStateFactory $agentStateFactory;
    private RepairService $repairService;
    private FeatureService $featureService;
    private CloudFeatureService $cloudFeatureService;

    public function __construct(
        AgentConnectivityService $agentConnectivityService,
        EncryptionService $encryptionService,
        Service $billingService,
        AgentConfigFactory $agentConfigFactory,
        AgentStateFactory $agentStateFactory,
        RepairService $repairService,
        FeatureService $featureService,
        CloudFeatureService $cloudFeatureService
    ) {
        $this->agentConnectivityService = $agentConnectivityService;
        $this->encryptionService = $encryptionService;
        $this->billingService = $billingService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->agentStateFactory = $agentStateFactory;
        $this->repairService = $repairService;
        $this->featureService = $featureService;
        $this->cloudFeatureService = $cloudFeatureService;
    }

    public function commit()
    {
        $this->context->updateBackupStatus(BackupStatusService::STATE_PREFLIGHT);

        $this->assertCloudAllowsBackups();
        $this->assertNotArchived();
        $this->assertNotReplicated();

        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $this->assertConnectivityAndAgentTypeUnchanged($agent);
        $this->assertAgentNeedsReboot();
        $this->setFlagIfAgentWantsReboot();

        $this->assertDriverIsLoaded();
        $this->cancelStaleJobIfNeeded();
        $this->assertDatasetIsMounted();
        $this->assertAgentIsUnsealedIfEncrypted();
        $this->verifyDeviceNotOutOfService();
        $this->assertOsUpdateNotPending();

        $this->context->reloadAsset();
    }

    public function cleanup()
    {
    }

    /**
     * Assert that the device is allowed to take backups based on if the cloud allows it.
     */
    public function assertCloudAllowsBackups()
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_CLOUD_ALLOWS_BACKUP_CHECK)) {
            return;
        }

        $allowed = $this->cloudFeatureService->isSupported(CloudFeatureService::FEATURE_BACKUP_ENABLED);
        if (!$allowed) {
            $this->context->getLogger()->critical(
                'BAK0043 Backups are not allowed on this device because the feature' .
                'is not enabled in the cloud. This may be due to the device service being expired.'
            );

            throw new BackupException('Backup feature is disabled in the cloud');
        }
    }

    /**
     * Verify the asset is not archived.
     */
    private function assertNotArchived()
    {
        $agentConfig = $this->agentConfigFactory->create($this->context->getAsset()->getKeyName());
        if ($agentConfig->isArchived()) {
            $message = 'Archived assets do not support backup';
            $this->context->getLogger()->warning('BAK0124 ' . $message);
            throw new BackupException($message);
        }
    }

    /**
     * Verify the asset is not replicated.
     */
    private function assertNotReplicated()
    {
        if ($this->context->getAsset()->getOriginDevice()->isReplicated()) {
            $message = 'Replicated assets do not support backup';
            $this->context->getLogger()->warning('BAK0024 ' . $message);
            throw new BackupException($message);
        }
    }

    /**
     * Assert if not connected, log different error if agent type changed.
     * @param Agent $agent
     */
    private function assertConnectivityAndAgentTypeUnchanged(Agent $agent)
    {
        if ($agent->getPlatform()->isAgentLess() || $agent->isDirectToCloudAgent()) {
            return;
        }

        try {
            $this->assertConnectivity();
        } catch (Exception $e) {
            if ($this->agentConnectivityService->existingAgentHasNewType($agent)) {
                $existingPlatform = $agent->getPlatform();
                switch ($existingPlatform) {
                    case AgentPlatform::DATTO_WINDOWS_AGENT():
                        $logCode = "BAK0027";
                        break;
                    case AgentPlatform::SHADOWSNAP():
                        $logCode = "BAK0028";
                        break;
                    case AgentPlatform::DATTO_LINUX_AGENT():
                        $logCode = "BAK0029";
                        break;
                    case AgentPlatform::DATTO_MAC_AGENT():
                        $logCode = "BAK0030";
                        break;
                    default:
                        $logCode = "BAK0032";
                        break;
                }
                $existingPlatformName = $existingPlatform->getFriendlyName();
                try {
                    $newPlatformName = $this->agentConnectivityService
                        ->determineAgentPlatform($agent->getFullyQualifiedDomainName())
                        ->getFriendlyName();
                } catch (Exception $ex) {
                    $newPlatformName = 'Unknown Agent';
                }

                $message = "$logCode We have detected an agent type change on your protected system from" .
                    " $existingPlatformName to $newPlatformName.  The previous" .
                    " backup chain prevents the new agent from pairing to this device.";
                $this->context->getLogger()->critical(
                    $message,
                    ['existingPlatform' => $existingPlatformName,
                    'newPlatform' => $newPlatformName]
                );
                throw new BackupException($message);
            } else {
                $this->context->getLogger()->critical('BAK0013 ' . $e->getMessage());
                throw new BackupException($e->getMessage());
            }
        }
    }

    /**
     * Verify connectivity to the agent
     */
    private function assertConnectivity()
    {
        $asset = $this->context->getAsset();
        /** @var Agent $agent */
        $agent = $asset;
        $connectivityState = $this->agentConnectivityService->checkExistingAgentConnectivity($agent);
        if ($connectivityState !== AgentConnectivityService::STATE_AGENT_ACTIVE) {
            $this->context->getLogger()->warning('BAK0011 Cannot connect to the host - attempting auto repair');
            $repaired = $this->repairService->autoRepair($agent->getKeyName());
            if ($repaired) {
                $this->context->getLogger()->debug('BAK0012 Continuing with backup since agent communication was repaired');
            } else {
                $message = 'Cannot connect to the host - aborting backup';
                throw new BackupException($message);
            }
        }

        $connectivityBackupAlertCodes = ['BAK0013', 'BAK0027', 'BAK0028', 'BAK0029', 'BAK0030', 'BAK0032'];
        $this->context->clearAlerts($connectivityBackupAlertCodes);
    }

    /**
     * Check if the agent system needs to reboot.
     * Backups will not work if the agent has updated to a new driver version without rebooting. This also applies for
     * the first installation of the agent software.
     */
    private function assertAgentNeedsReboot()
    {
        $asset = $this->context->getAsset();
        if (!($asset instanceof Agent && $asset->getPlatform()->isAgentLess())) {
            // Agentless api should not be initialized until after the WaitForOpenAgentlessSessions stage
            $this->context->getAgentApi()->initialize();
        }

        $agentState = $this->agentStateFactory->create($this->context->getAsset()->getKeyName());

        if ($this->context->getAgentApi()->needsReboot()) {
            $agentState->touch('needsReboot');
            $message = 'This agent requires a reboot of the protected system. Please reboot the protected system.';
            $this->context->getLogger()->critical('BAK0015 ' . $message);
            throw new BackupException($message);
        }

        $agentState->clear('needsReboot');
        $this->context->clearAlert('BAK0015');
    }

    private function setFlagIfAgentWantsReboot()
    {
        $agentState = $this->agentStateFactory->create($this->context->getAsset()->getKeyName());
        if ($this->context->getAgentApi()->wantsReboot()) {
            $agentState->touch('wantsReboot');
            $this->context->getLogger()->warning('BAK0016 This agent desires a reboot of the protected system to apply changes.');
        } else {
            $agentState->clear('wantsReboot');
        }
    }

    /**
     * Verify that the driver is loaded.
     * This is for ShadowSnap only
     */
    private function assertDriverIsLoaded()
    {
        $asset = $this->context->getAsset();
        /** @var Agent $agent */
        $agent = $asset;
        $platform = $agent->getPlatform();
        if ($platform === AgentPlatform::SHADOWSNAP() &&
            !$agent->getDriver()->isStcDriverLoaded()) {
            $message = 'Agent Driver is not loaded. Please restart the system to load the driver';
            $this->context->getLogger()->critical('BAK0022 ' . $message);
            throw new BackupException($message);
        }
        $this->context->clearAlert('BAK0022');
    }

    /**
     * Verify that the agent dataset is mounted
     */
    private function assertDatasetIsMounted()
    {
        $dataset = $this->context->getAsset()->getDataset();
        $isMounted = $dataset->getAttribute('mounted');
        if (!$isMounted || $isMounted === 'no') {
            $message = 'ZFS dataset is not mounted, cannot proceed with backup';
            $this->context->getLogger()->critical('BAK3985 ' . $message);
            throw new BackupException($message);
        }
        $this->context->clearAlert('BAK3985');
    }

    /**
     * Verify that an encrypted agent is unsealed.
     */
    private function assertAgentIsUnsealedIfEncrypted()
    {
        $assetKeyName = $this->context->getAsset()->getKeyName();
        if ($this->encryptionService->isEncrypted($assetKeyName) &&
            !$this->encryptionService->isAgentMasterKeyLoaded($assetKeyName)) {
            $message = "Backup failed because backup image files have not been decrypted";
            $this->context->getLogger()->critical('ENC1012 ' . $message);
            throw new BackupException($message);
        }
        $this->context->clearAlert('ENC1012');
    }

    /**
     * Verify device is not out of service
     */
    private function verifyDeviceNotOutOfService()
    {
        if ($this->billingService->isOutOfService()) {
            $this->context->getLogger()->critical('BIL0001 Cannot perform backup due to out of service device.');
            throw new BackupException('Out of service device');
        }
    }

    private function assertOsUpdateNotPending()
    {
        $osUpdatePending = $this->context->getAgentApi()->needsOsUpdate();
        $this->context->setOsUpdatePending($osUpdatePending);
        if ($osUpdatePending) {
            $message = 'A system update is pending for this machine. Screenshot verification will likely fail ' .
            'for this backup. Reboot the protected system and backup again for successful ' .
            'screenshot verification.';
            $this->context->getLogger()->warning('BAK0040 ' . $message);
        }
    }

    private function cancelStaleJobIfNeeded()
    {
        $jobUuid = $this->context->getCurrentJob()->getUuid();
        if ($jobUuid) {
            $this->context->getLogger()->debug("BAK0040 Detected running backup job, attempting cancel ($jobUuid)");
            $result = $this->context->getAgentApi()->cancelBackup($jobUuid);
            $cancelSuccess = $result['success'] ?? false;
            if ($cancelSuccess) {
                $this->context->getCurrentJob()->cleanup();
                $this->context->getLogger()->debug("BAK0041 Cancelled previous backup job ($jobUuid)");
            } else {
                // Log the failure and attempt to continue.  The cancel call can fail if the UUID is invalid or the
                // job is already cancelled, so we don't want to fail the backup here.
                $this->context->getLogger()->warning("BAK0042 Failed to cancel previous backup job ($jobUuid)");
            }
        }
    }
}
