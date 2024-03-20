<?php

namespace Datto\Config\RepairTask;

use Datto\Common\Utility\Filesystem;
use Datto\Core\Configuration\ConfigRepairTaskInterface;

/**
 * Config RepairTask to remove unused diag ssh key from authorized keys file for root and backup-admin users
 */
class RemoveDiagSSHKey implements ConfigRepairTaskInterface
{

    public const ROOT_AUTHORIZED_KEYS = '/root/.ssh/authorized_keys';
    public const BACKUP_ADMIN_AUTHORIZED_KEYS = '/datto/backup-admin/.ssh/authorized_keys';
    public const LINE = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDmvLrqvEUjSfC7o9S9jEq5/P0Tr11IA1OehnaAoXZdXMjJ097wSS/fG8KWvnATkCqtouWjuOPu1Fi7bi6GjHsVY51QVaNOBqIGZAKm6NFSsyrca4HY1qHiAIok6UNqBVXozf9t3hrWVQrCjb2exzNvQ51DC+HU732Aii5VOzXsgtTeyzaywamk/MW+iUlyC1Gp7XHs0Xls6FauGW0znog6NwzstJQUubNaT9nptbA6UUKLtbCEP/a614WgzYIIOiJIq8RLrsmH9+NOCdcV++55gK/klln/N6gEqVTDtfun+xmX3hRqdyTjTvyMA+4SNW8Po++l8AZMe780+mJZSwoN diag';

    private Filesystem $filesystem;

    public function __construct(
        Filesystem $filesystem
    ) {
        $this->filesystem = $filesystem;
    }

    public function run(): bool
    {
        $changesMade = false;
        if ($this->filesystem->exists(self::ROOT_AUTHORIZED_KEYS)) {
            $changesMade = $this->removeDiagKey(self::ROOT_AUTHORIZED_KEYS);
        }
        if ($this->filesystem->exists(self::BACKUP_ADMIN_AUTHORIZED_KEYS)) {
            if ($this->removeDiagKey(self::BACKUP_ADMIN_AUTHORIZED_KEYS)) {
                $changesMade = true;
            }
        }
        return $changesMade;
    }

    /**
     * Removes unused ssh key from specified authorized keys file, if the file does not
     * contain the key no updates will be made
     * @param String $filePath
     * @return bool Whether the file was changed
     */
    private function removeDiagKey(string $filePath): bool
    {
        $contents = $this->filesystem->fileGetContents($filePath);
        if ($contents && str_contains($contents, self::LINE)) {
            $contents = str_replace(self::LINE . "\n", '', $contents);
            $this->filesystem->filePutContents($filePath, $contents);
            return true;
        } else {
            return false;
        }
    }
}
