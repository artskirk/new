<?php

namespace Datto\Service\AssetManagement\Create\Stages;

use Datto\Asset\Agent\AgentConnectivityService;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\CloudEncryptionService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\AssetInfoSyncService;
use Datto\Cloud\AgentVolumeService;
use Datto\Cloud\SpeedSync;
use Datto\Config\AgentStateFactory;
use Datto\Service\AssetManagement\Create\CreateAgentProgress;
use Datto\Util\Email\EmailService;
use Datto\Util\Email\Generator\AddAssetEmailGenerator;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Responsible for setting up things that are not required for immediate use of the agent.
 *
 * This stage SHOULD NOT modify the agent configuration.
 * We want to avoid collisions because the add agent wizard will be calling api endpoints
 * to configure settings at this point. If you need to modify the agent, add it to CreateAgent.php instead.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class PostCreate extends AbstractCreateStage
{
    /** @var AgentService */
    private $agentService;

    /** @var CloudEncryptionService */
    private $cloudEncryptionService;

    /** @var AddAssetEmailGenerator */
    private $addAssetEmailGenerator;

    /** @var EmailService */
    private $emailService;

    /** @var AssetInfoSyncService */
    private $assetInfoSyncService;

    /** @var SpeedSync */
    private $speedSync;

    /** @var AgentVolumeService */
    private $agentVolumeService;

    /** @var AgentConnectivityService */
    private $agentConnectivityService;

    /** @var AgentStateFactory */
    private $agentStateFactory;

    /** @var EncryptionService */
    private $encryptionService;

    public function __construct(
        AgentService $agentService,
        CloudEncryptionService $cloudEncryptionService,
        AddAssetEmailGenerator $addAssetEmailGenerator,
        EmailService $emailService,
        AssetInfoSyncService $assetInfoSyncService,
        SpeedSync $speedSync,
        AgentVolumeService $agentVolumeService,
        AgentConnectivityService $agentConnectivityService,
        AgentStateFactory $agentStateFactory,
        EncryptionService $encryptionService
    ) {
        $this->agentService = $agentService;
        $this->cloudEncryptionService = $cloudEncryptionService;
        $this->addAssetEmailGenerator = $addAssetEmailGenerator;
        $this->emailService = $emailService;
        $this->assetInfoSyncService = $assetInfoSyncService;
        $this->speedSync = $speedSync;
        $this->agentVolumeService = $agentVolumeService;
        $this->agentConnectivityService = $agentConnectivityService;
        $this->agentStateFactory = $agentStateFactory;
        $this->encryptionService = $encryptionService;
    }

    /**
     * This is written to prevent exceptions from ending the stage early so we can attempt each function.
     * Failures in this stage are not critical since they do not prevent the immediate use of the agent and other
     * background processes fix them eventually.
     */
    public function commit()
    {
        $logger = $this->context->getLogger();

        try {
            $agent = $this->agentService->get($this->context->getAgentKeyName());
            $email = $this->addAssetEmailGenerator->generate('agent', $agent->getPairName());
            $this->emailService->sendEmail($email);

            $this->syncWithDeviceWeb($logger);

            $this->addToSpeedsync($logger);

            $this->agentConnectivityService->updateAgentParameters($agent, $logger);
        } catch (Throwable $e) {
            $logger->error('PHD0011 Failure during PostCreate. Continuing because agent is already created', ['error' => $e->getMessage()]);
            $this->addErrorMessage($e->getMessage());
        }

        $agentState = $this->agentStateFactory->create($this->context->getAgentKeyName());
        $createProgress = new CreateAgentProgress();
        $agentState->loadRecord($createProgress);
        if ($createProgress->getState() !== CreateAgentProgress::POST_FAIL) {
            // agent is now 100% created
            $logger->info('AGT3010 Agent added successfully.'); // log code is used by device-web see DWI-2252
            $createProgress->setState(CreateAgentProgress::FINISHED);
            $agentState->saveRecord($createProgress);
        }
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // not needed
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        // If we reached this stage, we shouldn't rollback because the agent exists
    }

    private function syncWithDeviceWeb(DeviceLoggerInterface $logger)
    {
        try {
            // This needs to run before 'speedsync add' for peer to peer
            $this->assetInfoSyncService->sync($this->context->getAgentKeyName());
        } catch (Throwable $e) {
            // Don't allow this to fail the creation, speedsync add may still succeed
            $logger->error('PHD0009 Asset info failed to sync', ['error' => $e->getMessage()]);
            $this->addErrorMessage($e->getMessage());
        }

        // This and uploadEncryptionKeys must come after assetInfoSyncService->sync() since it uses data in deviceVols
        try {
            $this->agentVolumeService->update($this->context->getAgentKeyName());
        } catch (Exception $e) {
            // It's fine if this fails during paring since it gets called periodically on a systemd timer
            $logger->warning('AGT3016 Agent volume update failed', ['error' => $e->getMessage()]);
            $this->addErrorMessage($e->getMessage());
        }

        if ($this->encryptionService->isEncrypted($this->context->getAgentKeyName())) {
            try {
                $this->cloudEncryptionService->uploadEncryptionKeys($this->context->getAgentKeyName(), false);
            } catch (Throwable $e) {
                $logger->error('PHD0008 Encryption key upload failed', ['error' => $e->getMessage()]);
                $this->addErrorMessage($e->getMessage());
            }
        }
    }

    private function addToSpeedsync(DeviceLoggerInterface $logger)
    {
        $offsiteTarget = $this->context->getOffsiteTarget();
        $zfsPath = 'homePool/home/agents/' . $this->context->getAgentKeyName();

        $logger->debug("PHD0010 Calling speedsync add: $zfsPath ($offsiteTarget)");
        $exitCode = $this->speedSync->add($zfsPath, $offsiteTarget);

        if ($exitCode !== 0) {
            // It's ok if this fails here since 'speedsync add' gets called often and is likely to get fixed later
            $this->addErrorMessage("Failed to add agent to speedsync. ExitCode=$exitCode");
        }
    }

    /**
     * Adds error messages to the CreateAgentProgress file and sets the state to POST_FAIL
     */
    private function addErrorMessage(string $message)
    {
        $agentState = $this->agentStateFactory->create($this->context->getAgentKeyName());
        $createProgress = new CreateAgentProgress();
        $createProgress->setState(CreateAgentProgress::POST_FAIL);

        // We don't want to lose any existing error messages
        if (!empty($createProgress->getErrorMessage())) {
            $message = $createProgress->getErrorMessage() . PHP_EOL . $message;
        }

        $createProgress->setErrorMessage($message);
        $agentState->saveRecord($createProgress);
    }
}
