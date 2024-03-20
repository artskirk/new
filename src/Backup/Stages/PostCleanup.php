<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\DiffMergeService;
use Datto\Common\Utility\Filesystem;

/**
 * This backup stage cleans up any artifacts from backup process.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class PostCleanup extends BackupStage
{
    const FORCE_FULL_FILE_EXT = '.forceFull';
    const INHIBIT_ROLLBACK_EXT = '.inhibitRollback';

    /** @var Filesystem */
    private $filesystem;

    private DiffMergeService $diffMergeService;

    public function __construct(Filesystem $filesystem, DiffMergeService $diffMergeService)
    {
        $this->filesystem = $filesystem;
        $this->diffMergeService = $diffMergeService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->clearBackupTypeFiles();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * Clear out the backup type files
     */
    private function clearBackupTypeFiles()
    {
        $assetKeyPath = Agent::KEYBASE . $this->context->getAsset()->getKeyName();

        $filesToRemove[] = $assetKeyPath . self::FORCE_FULL_FILE_EXT;
        $filesToRemove[] = $assetKeyPath . self::INHIBIT_ROLLBACK_EXT;
        $changed = false;

        foreach ($filesToRemove as $fileToRemove) {
            if ($this->filesystem->exists($fileToRemove)) {
                $this->filesystem->unlink($fileToRemove);
                $changed = true;
            }
        }

        $asset = $this->context->getAsset();
        if ($asset->supportsDiffMerge()) {
            $this->diffMergeService->clearDoDiffMerge($asset->getKeyName());
        }

        if ($changed) { // reload asset so changes aren't blown away
            $this->context->reloadAsset();
        }
    }
}
