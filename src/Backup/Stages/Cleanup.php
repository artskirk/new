<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\VolumesService;
use Datto\Backup\BackupContext;
use Datto\Backup\LoopDeviceCleanup;
use Datto\Config\AgentConfig;
use Datto\Iscsi\IscsiTarget;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * This backup stage cleans up any remaining artifacts from previous backups.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Cleanup extends BackupStage implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    private Filesystem $filesystem;
    private IscsiTarget $iscsi;
    private LoopDeviceCleanup $loopDeviceCleanup;
    private VolumesService $volumesService;

    public function __construct(
        Filesystem $filesystem,
        IscsiTarget $iscsiTarget,
        LoopDeviceCleanup $loopDeviceCleanup,
        VolumesService $volumesService
    ) {
        $this->filesystem = $filesystem;
        $this->iscsi = $iscsiTarget;
        $this->loopDeviceCleanup = $loopDeviceCleanup;
        $this->volumesService = $volumesService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->cleanup();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        $this->clearIscsiTargets();
        $this->clearHungLoops();
        $this->clearOldBackupFiles();
        $this->deleteVolumesAfterRollback();
    }

    /**
     * Clear any existing iSCSI targets and backstores
     */
    private function clearIscsiTargets()
    {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $this->iscsi->removeAgentIscsiEntities($agent);
    }

    /**
     * Clean up any hung loops that might exist, making sure that we cant clean
     * previous backups' loops.
     */
    private function clearHungLoops()
    {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $isEncrypted = $agent->getEncryption()->isEnabled();
        $agentStoragePath = $this->getAgentStoragePath();
        $this->loopDeviceCleanup->cleanLoopsForImageFilesInPath($agentStoragePath, $isEncrypted);
    }

    /**
     * Remove unneeded / old files from the agent's storage
     */
    private function clearOldBackupFiles()
    {
        $filesToRemove = $this->getFilesToRemove();
        foreach ($filesToRemove as $fileToRemove) {
            if ($this->filesystem->exists($fileToRemove)) {
                $this->filesystem->unlink($fileToRemove);
            }
        }
    }

    /**
     * Remove any volumes that were previously deleted that we just recovered during the rollback stage
     */
    private function deleteVolumesAfterRollback()
    {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $keyGUID = $agent->getKeyName();
        $keySearchPath = sprintf(AgentConfig::BASE_KEY_CONFIG_PATH . "/" . $keyGUID . "." . VolumesService::DELETE_AFTER_ROLLBACK_KEY_PATTERN, "*");
        $deleteKeys = glob($keySearchPath);
        foreach ($deleteKeys as $deleteKey) {
            $keyParts = explode(".", $deleteKey);
            $volumeGuid = $keyParts[2];
            $this->logger->info('BAK0005 Found delete after rollback key. Removing all files for volume from live dataset', ['asset' => $agent->getKeyName(), 'volumeGuid' => $volumeGuid]);
            $this->volumesService->destroyVolumeDatasetByGuid($agent, $volumeGuid);
        }
    }

    /**
     * Get the path to the agent's storage (live dataset)
     *
     * @return string
     */
    private function getAgentStoragePath(): string
    {
        $assetKeyName = $this->context->getAsset()->getKeyName();
        $agentStoragePath = BackupContext::AGENTS_PATH . $assetKeyName;
        return $agentStoragePath;
    }

    /**
     * Get a list of files to remove from the agent's storage path
     *
     * @return array
     */
    private function getFilesToRemove(): array
    {
        $agentStoragePath = $this->getAgentStoragePath() . '/';

        $filesToRemove = [
            // Even though the code generating this has been removed, this line
            // must stay in place until all (most) agents have taken a new backup
            $agentStoragePath . 'filesystemCheckFailed',

            // Remove undesirable files potentially created by the old HIR (which used to run post-backup)
            $agentStoragePath . 'HIRFailed',
            $agentStoragePath . 'boot.datto'
        ];

        $filesToRemove = array_merge($filesToRemove, $this->filesystem->glob($agentStoragePath . "*.vmdk"));

        return $filesToRemove;
    }
}
