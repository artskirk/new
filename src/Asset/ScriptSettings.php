<?php

namespace Datto\Asset;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Exception;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Class ScriptSettings
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ScriptSettings
{
    /** @const string Script folder location */
    const SCRIPT_TARGET_FOLDER = '/datto/config/keys/scripts';

    /** @var Filesystem */
    private $filesystem;

    /** @var VerificationScript[] */
    private $scripts;

    /** @var VerificationScript[] */
    private $deletedScripts;

    /** @var string */
    private $assetName;

    /** @var string */
    private $scriptDir;

    /**
     * VerificationSettings constructor.
     * @param String $assetName
     * @param VerificationScript[] $scripts
     * @param Filesystem|null $filesystem
     */
    public function __construct(
        $assetName,
        array $scripts = [],
        Filesystem $filesystem = null
    ) {
        $this->assetName = $assetName;
        $this->scripts = $scripts;
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());

        $this->deletedScripts = [];
        $this->scriptDir = self::SCRIPT_TARGET_FOLDER . '/' . $this->assetName . '/';
    }

    /**
     * Performs the synchronization of metadata and file names.
     */
    public function sync(): void
    {
        $scriptDir = $this->getScriptDirectory();

        // Rename files if tier has changed
        foreach ($this->scripts as $script) {
            $fullPathToScript = $scriptDir . $script->getFilename();

            $oldFileExists = $this->filesystem->exists($fullPathToScript);
            $tierChanged = $script->getFilename() !== $script->getFullUniqueId();

            if ($oldFileExists && $tierChanged) {
                $newFullPath = $scriptDir . $script->getFullUniqueId();
                $this->filesystem->rename($fullPathToScript, $newFullPath);
            }
        }

        // Remove files if the script was deleted
        foreach ($this->deletedScripts as $scriptToDelete) {
            $fullPathToScript = $scriptDir . $scriptToDelete->getFilename();
            $this->filesystem->unlink($fullPathToScript);
        }
    }

    /**
     * @return VerificationScript[]
     */
    public function getScripts()
    {
        return $this->scripts;
    }

    /**
     * @return null|VerificationScript[]
     */
    public function getDeletedScripts()
    {
        return $this->deletedScripts;
    }

    /**
     * Get the script directory for the particular asset.
     *
     * @return string the directory where the asset's scripts are stored.
     */
    public function getScriptDirectory()
    {
        return $this->scriptDir;
    }

    /**
     * Returns a list of all the scripts as file path strings
     *
     * @return string[]
     */
    public function getScriptFilePaths()
    {
        $filePaths = [];
        foreach ($this->scripts as $script) {
            $filePaths[$this->scriptDir . $script->getFullUniqueId()] = $script->getName();
        }

        return $filePaths;
    }

    /**
     * Saves script file to the device under:
     *     /datto/config/keys/scripts/myAgent/uniqid_script.extension
     *
     * @param UploadedFile $tmpFile Temp file to be saved
     * @param string $uniqid A unique id
     */
    public function saveScript(UploadedFile $tmpFile, $uniqid = null): void
    {
        // When we truly support tiers, we'll have to pass $tier into this function.
        $tier = count($this->scripts) + 1;
        $tier = sprintf('%02d', $tier);

        if ($uniqid) {
            $script = new VerificationScript($tmpFile->getClientOriginalName(), $uniqid, $tier);
            $targetFile = $this->generateFilePath($script);
            if ($this->filesystem->exists($targetFile)) {
                throw new Exception("File exists, will not save.");
            }
        } else {
            do {
                $uniqid = uniqid();
                $script = new VerificationScript($tmpFile->getClientOriginalName(), $uniqid, $tier);
                $targetFile = $this->generateFilePath($script);
            } while ($this->filesystem->exists($targetFile));
        }

        // moveUploadedFile will NOT make a directory!!!!
        if (!$this->filesystem->isDir($this->scriptDir)) {
            $this->filesystem->mkdir($this->scriptDir, true, 0755);
        }

        $moved = $this->filesystem->moveUploadedFile($tmpFile->getPathname(), $targetFile);

        //moveUploadedFile returns null on success, false otherwise
        if ($moved === false) {
            throw new Exception('Fail to save script: ' . $script->getName());
        }

        $this->scripts[] = $script;
    }

    /**
     * Deletes a single script.
     * Technically, it just marks the script for deletion. Actual deletion doesn't occur
     * until the asset is saved.
     *
     * @param VerificationScript $script
     */
    public function deleteScript($script): void
    {
        foreach ($this->scripts as $index => $currentScript) {
            if ($currentScript->getFullUniqueId() === $script->getFullUniqueId()) {
                $this->deletedScripts[] = $currentScript;
                unset($this->scripts[$index]);
                break;
            }
        }

        // Won't have to regenerate tiers when we have proper tier support
        $this->regenerateTiers();
    }

    /**
     * Deletes all scripts.
     * Technically, it just marks the scripts for deletion. Actual deletion doesn't occur
     * until the asset is saved.
     */
    public function deleteAllScripts(): void
    {
        $this->deletedScripts = array_merge($this->deletedScripts, $this->scripts);
        $this->scripts = [];
    }

    /**
     * Updates the script execution order
     *
     * @param VerificationScript[] $newlyOrderedScripts
     */
    public function updateScriptExecutionOrder($newlyOrderedScripts): void
    {
        $totalMatches = 0;
        foreach ($newlyOrderedScripts as $script) {
            foreach ($this->scripts as $currentScript) {
                if ($currentScript->getFullUniqueId() === $script->getFullUniqueId()) {
                    $totalMatches++;
                }
            }
        }

        if ($totalMatches != count($this->scripts)) {
            throw new Exception('Given script array does not match current script array, will not re-order scripts.');
        }

        $this->scripts = $newlyOrderedScripts;
    }

    /**
     * Regenerates tiers for scripts.
     * This function should be removed when we implement proper tier support.
     */
    private function regenerateTiers(): void
    {
        $index = 1;
        foreach ($this->scripts as $script) {
            $tier = sprintf('%02d', $index);
            $script->setTier($tier);
            $index++;
        }
        $this->scripts = array_values($this->scripts);
    }

    /**
     * @param VerificationScript $script
     * @return string
     */
    private function generateFilePath($script)
    {
        return $this->scriptDir . $script->getFullUniqueId();
    }
}
