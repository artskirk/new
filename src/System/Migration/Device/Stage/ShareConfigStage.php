<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Samba\SambaManager;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\System\Ssh\SshClient;
use Datto\Common\Utility\Filesystem;

/**
 * Copy the system-level share config to the new device.
 * @author Peter Geer <pgeer@datto.com>
 */
class ShareConfigStage extends AbstractMigrationStage
{
    const BACKUP_SUFFIX = ".original";
    const KEY_PATH = "/datto/config/keys/";

    /** @var SshClient */
    private $sshClient;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        Context $context,
        SshClient $sshClient,
        Filesystem $filesystem
    ) {
        parent::__construct($context);
        $this->sshClient = $sshClient;
        $this->filesystem = $filesystem;
    }

    public function commit()
    {
        $targets = $this->context->getTargets();
        // A full migration moves all configuration to a new empty device.
        $fullMigration = in_array(DeviceConfigStage::DEVICE_TARGET, $targets);
        if ($fullMigration) {
            $this->backupLocalConfigFiles();
            $this->copyRemoteConfigFiles();
        } else {
            // backup sambadevice config file
            $this->backupSambaDeviceConfigFile();
            // import remote config files
            $this->importSambaDeviceConfig();
        }
    }

    public function cleanup()
    {
        if ($this->filesystem->exists(SambaManager::SAMBA_CONF_FILE . static::BACKUP_SUFFIX)) {
            $this->filesystem->unlink(SambaManager::SAMBA_CONF_FILE . static::BACKUP_SUFFIX);
        }
        if ($this->filesystem->exists(SambaManager::DEVICE_CONF_FILE . static::BACKUP_SUFFIX)) {
            $this->filesystem->unlink(SambaManager::DEVICE_CONF_FILE . static::BACKUP_SUFFIX);
        }
    }

    public function rollback()
    {
        if ($this->filesystem->exists(SambaManager::SAMBA_CONF_FILE . static::BACKUP_SUFFIX)) {
            $this->filesystem->copy(SambaManager::SAMBA_CONF_FILE . static::BACKUP_SUFFIX, SambaManager::SAMBA_CONF_FILE);
        }
        if ($this->filesystem->exists(SambaManager::DEVICE_CONF_FILE . static::BACKUP_SUFFIX)) {
            $this->filesystem->copy(SambaManager::DEVICE_CONF_FILE . static::BACKUP_SUFFIX, SambaManager::DEVICE_CONF_FILE);
        }
        $this->cleanup();
    }

    private function backupLocalConfigFiles()
    {
        if ($this->filesystem->exists(SambaManager::SAMBA_CONF_FILE)) {
            $this->filesystem->copy(
                SambaManager::SAMBA_CONF_FILE,
                SambaManager::SAMBA_CONF_FILE . static::BACKUP_SUFFIX
            );
        }
        if ($this->filesystem->exists(SambaManager::DEVICE_CONF_FILE)) {
            $this->filesystem->copy(
                SambaManager::DEVICE_CONF_FILE,
                SambaManager::DEVICE_CONF_FILE . static::BACKUP_SUFFIX
            );
        }
    }

    private function backupSambaDeviceConfigFile()
    {
        if ($this->filesystem->exists(SambaManager::DEVICE_CONF_FILE)) {
            $this->filesystem->copy(
                SambaManager::DEVICE_CONF_FILE,
                SambaManager::DEVICE_CONF_FILE . static::BACKUP_SUFFIX
            );
        }
    }

    private function copyRemoteConfigFiles()
    {
        $this->sshClient->copyFromRemote(SambaManager::SAMBA_CONF_FILE, SambaManager::SAMBA_CONF_FILE);
        $this->sshClient->copyFromRemote(SambaManager::DEVICE_CONF_FILE, SambaManager::DEVICE_CONF_FILE, false);
    }

    private function importSambaDeviceConfig()
    {
        $targets = $this->context->getTargets();
        $outString = '';
        foreach ($targets as $target) {
            if ($this->filesystem->exists(self::KEY_PATH . "$target.samba")) {
                $outString .= "\tinclude = " . self::KEY_PATH . "$target.samba\n";
            }
        }
        if (strlen($outString) == 0) {
            return;
        }

        if ($this->filesystem->exists(SambaManager::DEVICE_CONF_FILE)) {
            $old = $this->filesystem->fileGetContents(SambaManager::DEVICE_CONF_FILE) ?? '';
            $new = $outString . $old;
        } else {
            $new = $outString;
        }
        $this->filesystem->filePutContents(SambaManager::DEVICE_CONF_FILE, $new);
    }
}
