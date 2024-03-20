<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Backup\BackupContext;
use Datto\Common\Utility\Filesystem;

/**
 * This backup stage copies the agent configuration files to the live dataset.
 * This will allow them to be captured as part of the snapshot.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class CopyAgentConfig extends BackupStage
{
    /** Agent files to be copied to the dataset */
    const DESIRED_KEY_FILES = [
        "alertConfig",
        "agentInfo",
        "backupEngine",
        "directToCloudAgentSettings",
        "emails",
        "emailSupression",
        "encryption",
        "encryptionKeyStash",
        "fullDiskBackup",
        "esxInfo",
        "include",
        "interval",
        "legacyVM",
        "offsiteControl",
        "offsiteMetrics",
        "offSitePoints",
        "offSitePointsCache",
        "offsiteRetention",
        "offsiteRetentionLimits",
        "offsiteSchedule",
        "recoveryPointsMeta",
        "ransomwareCheckEnabled",
        "ransomwareSuspensionEndTime",
        "rescueSettings",
        "retention",
        "retentionChangesJSON",
        "schedule",
        "screenshotNotification",
        "screenshotVerification",
        "scriptSettings",
        "shadowSnap",
        "storageUsage",
        "transfers",
        "vssExclude",
        "vssWriters"
    ];

    const MKDIR_MODE = 0777;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->copyAgentInfoFileToAgentDataset();
        $this->copyAgentKeyFilesToAgentDataset();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * Copy the agentInfo file into the dataset
     */
    private function copyAgentInfoFileToAgentDataset()
    {
        $assetKeyName = $this->context->getAsset()->getKeyName();
        $agentInfoFile = Agent::KEYBASE . $assetKeyName . '.agentInfo';
        $destinationDir = BackupContext::AGENTS_PATH . $assetKeyName . '/';

        if ($this->filesystem->exists($agentInfoFile)) {
            $this->filesystem->copy($agentInfoFile, $destinationDir);
            $this->reintroduceBugThatCloudReliesOn($destinationDir . $assetKeyName . '.agentInfo');
        }
    }

    /**
     *  Copies live relevant asset configuration files into the dataset
     */
    private function copyAgentKeyFilesToAgentDataset()
    {
        $assetKeyName = $this->context->getAsset()->getKeyName();
        $configPath = Agent::KEYBASE . $assetKeyName . '.*';
        $configFilesToBackup = $this->filesystem->glob($configPath);
        $destinationDir = BackupContext::AGENTS_PATH . $assetKeyName . '/config';

        if (!$this->filesystem->isDir($destinationDir)) {
            $this->filesystem->mkdir($destinationDir, false, self::MKDIR_MODE);
        } else {
            $configFilesFromPreviousBackup = $this->filesystem->glob($destinationDir . '/*');
            $configFilesToRemove = array_diff($configFilesFromPreviousBackup, $configFilesToBackup);
            foreach ($configFilesToRemove as $fileToRemove) {
                $this->filesystem->unlink($fileToRemove);
            }
        }

        foreach ($configFilesToBackup as $file) {
            $ext = $this->filesystem->extension($file);
            if (in_array($ext, self::DESIRED_KEY_FILES)) {
                $fileName = basename($file);
                $destinationFile = $destinationDir . '/' . $fileName;
                $this->filesystem->copy($file, $destinationFile);
            }
        }
    }

    /**
     * [BCDR-17337] For several years, we were putting the incorrect value in the 'type' field for agentless systems
     * in the agentInfo that was saved in the zfs dataset. The way the code was structured in backup meant we were
     * saving incorrect data in the agentInfo, then shortly after updating it with the correct info. Right in between,
     * we copied the agentInfo (which contained the incorrect type) from /datto/config/keys into the zfs dataset.
     *
     * These snapshots then got offsited to the cloud where someone could have attempted a cloud virtualization of them.
     * Node-api and cloudvm scripts look at the type field to know how to handle virtualizing them, but the code did
     * not know about agentless systems. Unfortunately, we need to reintroduce this bug because cloud can not release
     * their fix in time and we don't want to break cloud virts.
     *
     * This changes the type field in the agentInfo file saved in the zfs dataset to what it was before.
     * We change 'agentlessWindows' to 'windows' and 'agentlessLinux' to 'linux'.
     *
     * @param string $agentInfoFile The agentInfo file in the zfs dataset to modify
     */
    private function reintroduceBugThatCloudReliesOn(string $agentInfoFile)
    {
        $agentInfo = unserialize($this->filesystem->fileGetContents($agentInfoFile), ['allowed_types' => false]);

        if (AssetType::isType(AssetType::AGENTLESS_LINUX, $agentInfo)) {
            $agentInfo['type'] = AssetType::LINUX_AGENT;
        } elseif (AssetType::isType(AssetType::AGENTLESS_WINDOWS, $agentInfo)) {
            $agentInfo['type'] = AssetType::WINDOWS_AGENT;
        } else {
            return; // If it's not agentless linux or agentless windows then we're all set
        }

        $this->filesystem->filePutContents($agentInfoFile, serialize($agentInfo));
    }
}
