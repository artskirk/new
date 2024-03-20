<?php

namespace Datto\Restore\File;

use Datto\Asset\Asset;
use Datto\Restore\CloneSpec;
use Datto\Restore\Restore;
use Datto\Utility\Security\SecretString;

/**
 * Context for creating file restores.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class FileRestoreContext
{
    private Asset $asset;
    private int $snapshot;
    private ?CloneSpec $cloneSpec = null;
    private ?SecretString $passphrase = null;
    private ?string $restoreMount = null;
    private ?string $sambaShareName = null;
    private ?Restore $restore = null;
    private bool $withSftp;
    private bool $repairMode;
    private ?SftpCredentials $sftpCredentials = null;

    public function __construct(
        Asset $asset,
        int $snapshot,
        SecretString $passphrase = null,
        bool $withSftp = false,
        bool $repairMode = false
    ) {
        $this->asset = $asset;
        $this->snapshot = $snapshot;
        $this->passphrase = $passphrase;
        $this->withSftp = $withSftp;
        $this->repairMode = $repairMode;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function getSnapshot(): int
    {
        return $this->snapshot;
    }

    public function getPassphrase(): ?SecretString
    {
        return $this->passphrase;
    }

    public function getCloneSpec(): ?CloneSpec
    {
        return $this->cloneSpec;
    }

    public function setCloneSpec(CloneSpec $cloneSpec): void
    {
        $this->cloneSpec = $cloneSpec;
    }

    /**
     * Gets the full path to the mountpoint of the file restore.
     *
     * @return null|string
     */
    public function getRestoreMount(): ?string
    {
        return $this->restoreMount;
    }

    public function setRestoreMount(string $restoreMount): void
    {
        $this->restoreMount = $restoreMount;
    }

    public function getSambaShareName(): ?string
    {
        return $this->sambaShareName;
    }

    public function setSambaShareName(string $sambaShareName): void
    {
        $this->sambaShareName = $sambaShareName;
    }

    public function getRestore(): ?Restore
    {
        return $this->restore;
    }

    public function setRestore(Restore $restore): void
    {
        $this->restore = $restore;
    }

    public function getWithSftp(): bool
    {
        return $this->withSftp;
    }

    public function setWithSftp(bool $withSftp): void
    {
        $this->withSftp = $withSftp;
    }

    /**
     * @return bool True if it is a repair, false otherwise
     */
    public function getRepairMode(): bool
    {
        return $this->repairMode;
    }

    public function setRepairMode(bool $repairMode): void
    {
        $this->repairMode = $repairMode;
    }

    public function setSftpCredentials(SftpCredentials $sftpCredentials = null): void
    {
        $this->sftpCredentials = $sftpCredentials;
    }

    public function getSftpCredentials(): ?SftpCredentials
    {
        return $this->sftpCredentials;
    }
}
