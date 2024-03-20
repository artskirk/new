<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentDataUpdateService;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Api\BackupApiContextFactory;
use Datto\Asset\Agent\VolumesService;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\AssetService;
use Datto\Asset\RecoveryPoint\RecoveryPointHistoryRecord;
use Datto\Asset\Transfer;
use Datto\Asset\TransfersService;
use Datto\Backup\BackupContext;
use Datto\Backup\VmConfigurationBackupService;
use Datto\Common\Utility\Filesystem;
use Datto\Config\AgentConfig;
use Throwable;

/**
 * This backup stage updates the persisted asset information.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class UpdateAsset extends BackupStage
{

    /** @var TransfersService */
    private $transfersService;

    /** @var Filesystem */
    private $filesystem;

    /** @var AssetService */
    private $assetService;

    /** @var VolumesService */
    private $volumeService;

    /** @var VmConfigurationBackupService */
    private $vmConfigurationBackupService;

    /** @var AgentDataUpdateService */
    private $agentDataUpdateService;

    public function __construct(
        TransfersService $transfersService,
        Filesystem $filesystem,
        AssetService $assetService,
        VolumesService $volumesService,
        VmConfigurationBackupService $vmConfigurationBackupService,
        AgentDataUpdateService $agentDataUpdateService
    ) {
        $this->transfersService = $transfersService;
        $this->filesystem = $filesystem;
        $this->assetService = $assetService;
        $this->volumeService = $volumesService;
        $this->vmConfigurationBackupService = $vmConfigurationBackupService;
        $this->agentDataUpdateService = $agentDataUpdateService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        try {
            $this->initializeApi();
            $this->updateAgentInfo();

            if ($this->hasSnapshotBeenTaken()) {
                $this->handlePostSnapshotUpdates();
            }
            // todo: refactor the interaction of the context's asset object and the key files
            //   such that the asset in the context is updated, then serialized out to the key files,
            //   instead of updating the key files and reloading the context's asset
            $this->context->reloadAsset();
            $this->context->clearAlert('BAK7201');
        } catch (Throwable $e) {
            $this->context->getLogger()->critical('BAK7201 Critical backup failure: Could not update agent metadata');
            $this->context->getLogger()->debug('BAK7202 Backup failure error message: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        $this->context->getAgentApi()->cleanup();
    }

    private function initializeApi()
    {
        $this->context->getAgentApi()->initialize();
    }

    /**
     * @return bool
     */
    private function hasSnapshotBeenTaken(): bool
    {
        return $this->context->getSnapshotTime() !== BackupContext::SNAPSHOT_TIME_DEFAULT;
    }

    /**
     * Update the agent key files with information from the agent
     */
    private function updateAgentInfo()
    {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $platform = $agent->getPlatform();
        try {
            $agentRawData = $this->agentDataUpdateService->updateAgentInfo($agent->getKeyName(), $this->context->getAgentApi());
            $agent = $this->context->reloadAsset();
        } catch (Throwable $e) {
            if ($platform->isAgentless()) {
                throw $e;
            }
            // Some agents (not agentless) can fail to respond to a host call but can still backup successfully.
            // Backing up without the latest agent info is not correct but we prefer it to not backing up at all.
            $this->context->getLogger()->warning("BAK0026 Agent did not respond to the 'host' call." .
                'Continue anyway in case we can still backup. Error: ' . $e->getMessage());
            return;
        }

        if ($this->hasSnapshotBeenTaken()) {
            return;
        }

        switch ($platform) {
            case AgentPlatform::DATTO_WINDOWS_AGENT():
                /** @var WindowsAgent $agent */
                $this->saveWindowsAgentVmConfiguration($agent, $agentRawData->getHostResponse());
                break;
            case AgentPlatform::AGENTLESS():
            case AgentPlatform::AGENTLESS_GENERIC():
                $newEsxInfo = $agentRawData->getHostResponse()['esxInfo'];
                if (!empty($newEsxInfo['vmx'])) {
                    $this->vmConfigurationBackupService->saveVmConfigurationVmx($agent, $newEsxInfo['vmx']);
                } else {
                    $this->context->getLogger()->warning("BAK0033 Couldn't retrieve and save VMX configuration.");
                }
                break;
        }
    }

    /**
     * Run updates that should be run only during post-snapshot
     */
    private function handlePostSnapshotUpdates()
    {
        $this->updateRecoveryPoints();
        $this->updateTransfers();
        $this->removeDeleteAfterRollbackKeys();
    }

    /**
     * Update the recovery points with the current snapshot time
     */
    private function updateRecoveryPoints()
    {
        $pointsFile = $this->context->getAgentConfig()->getConfigFilePath('recoveryPoints');
        $recoveryPoints = array_filter(explode(PHP_EOL, $this->filesystem->fileGetContents($pointsFile)));
        $recoveryPoints[] = $this->context->getSnapshotTime();
        $this->filesystem->filePutContents($pointsFile, implode(PHP_EOL, $recoveryPoints) . PHP_EOL);

        try {
            $assetKeyName = $this->context->getAsset()->getKeyName();
            /** @var Agent $asset */
            // todo should be using AgentService in this class
            $asset = $this->assetService->get($assetKeyName);

            $missingVolumes = $this->volumeService->getAllMissingVolumeMetadata($asset->getKeyName());
            $this->context->setMissingVolumesResult($missingVolumes);

            $recoveryPoint = $asset->getLocal()->getRecoveryPoints()->getLast();
            if ($recoveryPoint) {
                $backupEngineConfigured = $this->context->getBackupEngineConfigured();
                $backupEngineUsed = $this->context->getBackupEngineUsed();
                $hasBackupEngineConfigured = $backupEngineConfigured &&
                    $backupEngineConfigured !== BackupApiContextFactory::BACKUP_TYPE_NONE;
                $hasBackupEngineUsed = $backupEngineUsed &&
                    $backupEngineUsed !== BackupApiContextFactory::BACKUP_TYPE_NONE;
                if ($hasBackupEngineConfigured && $hasBackupEngineUsed) {
                    $this->context->getLogger()->debug(
                        'BAK0021 ' .
                        'Backup engine used: ' .
                        $backupEngineUsed .
                        ' -- Backup engine configured: ' .
                        $backupEngineConfigured
                    );

                    $recoveryPoint->setEngineConfigured($backupEngineConfigured);
                    $recoveryPoint->setEngineUsed($backupEngineUsed);
                }

                $recoveryPoint->setVolumeBackupTypes($this->context->getVolumeBackupTypes());
                $recoveryPoint->setMissingVolumes($missingVolumes);
                $recoveryPoint->setFilesystemCheckResults($this->context->getFilesystemCheckResults());
                $recoveryPoint->setBackupForced($this->context->isForced());
                $recoveryPoint->setOsUpdatePending($this->context->isOsUpdatePending());

                $this->assetService->save($asset);
            } else {
                $this->context->getLogger()->debug(
                    'BAK0022 Could not find recovery point on asset, ignoring: ' .
                    $this->context->getSnapshotTime()
                );
            }
        } catch (Throwable $throwable) {
            $this->context->getLogger()->warning(
                'BAK0023 Unable to store recovery point metadata, ignoring: ' .
                $throwable->getMessage()
            );
        }
    }

    /**
     * Update the transfers file to include the most recent transfer size
     */
    private function updateTransfers()
    {
        $snapshotEpoch = $this->context->getSnapshotTime();
        $transferSize = $this->context->getAmountTransferred();

        $transfer = new Transfer($snapshotEpoch, $transferSize);
        $this->transfersService->add($this->context->getAsset()->getKeyName(), $transfer);

        $recoveryPointHistory = new RecoveryPointHistoryRecord();
        $this->context->getAgentConfig()->loadRecord($recoveryPointHistory);
        $recoveryPointHistory->addTransfer($snapshotEpoch, $transferSize);
        $recoveryPointHistory->addTotalUsed($snapshotEpoch, $this->context->getAsset()->getDataset()->getUsedSize());
        $this->context->getAgentConfig()->saveRecord($recoveryPointHistory);
    }

    /**
     * If the agent is running on VMware, save the configuration.
     *
     * @param WindowsAgent $agent
     * @param array $hostData
     */
    private function saveWindowsAgentVmConfiguration(WindowsAgent $agent, array $hostData)
    {
        $smbiosId = $hostData['smbiosID'] ?? '';

        if ($agent->isVirtualMachine() && $agent->getVmxBackupSettings()->isEnabled()) {
            try {
                $this->vmConfigurationBackupService->retrieveAndSaveVmxFromVm($agent, $smbiosId);
                $this->assetService->save($agent);
            } catch (\Throwable $throwable) {
                $this->context->getLogger()->debug("BAK0131 Couldn't backup VMX file: {$throwable->getMessage()}");
                $this->context->getLogger()->warning(
                    "BAK0130 Backup of VMX configuration failed, " .
                    "because a hypervisor connection for this virtual guest could not be found."
                );
            }
        }
    }

    /**
     * If the backup has taken a snapshot, then it is safe to remove any keys that mark volumes for deletion
     */
    private function removeDeleteAfterRollbackKeys()
    {
        $keySearchPath = sprintf(AgentConfig::BASE_KEY_CONFIG_PATH . "/" . $this->context->getAsset()->getKeyName() . "." . VolumesService::DELETE_AFTER_ROLLBACK_KEY_PATTERN, "*");
        $deleteTheseKeys = glob($keySearchPath);
        if (!empty($deleteTheseKeys)) {
            foreach ($deleteTheseKeys as $keyFileToDelete) {
                $this->filesystem->unlink($keyFileToDelete);
            }
        }
    }
}
