<?php

namespace Datto\Asset\Share\ExternalNas;

use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Asset\AssetUuidService;
use Datto\Asset\EmailAddressSettings;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\Share\Share;
use Datto\Dataset\ZVolDataset;
use Datto\System\SambaMount;
use Datto\Log\DeviceLoggerInterface;

/**
 * Representation of a external nas share.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ExternalNasShare extends Share
{
    const FORMAT_EXT4 = 'ext4';
    const FORMAT_NTFS = 'ntfs';
    const DEFAULT_FORMAT = self::FORMAT_EXT4;
    const DEFAULT_BLOCK_SIZE = 512;

    private string $format;
    private SambaMount $sambaMount;
    private bool $backupAcls;
    private ?string $ntlmAuthentication;
    private ?string $smbVersion;

    public function __construct(
        string $name,
        string $keyName,
        int $dateAdded,
        string $format,
        ZVolDataset $dataset,
        LocalSettings $local,
        OffsiteSettings $offsite,
        EmailAddressSettings $emailAddresses,
        SambaMount $sambaMount,
        DeviceLoggerInterface $logger,
        LastErrorAlert $lastError = null,
        string $uuid = '',
        bool $backupAcls = false,
        OriginDevice $originDevice = null,
        string $offsiteTarget = null,
        ?string $ntlmAuthentication = null,
        ?string $smbVersion = null
    ) {
        parent::__construct(
            $name,
            $keyName,
            AssetType::EXTERNAL_NAS_SHARE,
            $dateAdded,
            $dataset,
            $local,
            $offsite,
            $emailAddresses,
            $logger,
            $lastError,
            $uuid,
            $originDevice,
            $offsiteTarget
        );

        $this->format = $format;
        $this->sambaMount = $sambaMount;
        $this->backupAcls = $backupAcls;
        $this->ntlmAuthentication = $ntlmAuthentication;
        $this->smbVersion = $smbVersion;
    }

    /**
     * Creates a dev block (ZVol backend)
     * @param string $size Size of the zvol to create, ex '16T'
     */
    public function create(string $size): void
    {
        $this->dataset->create($size, $this->getFormat());
        $this->dataset->setAttribute(AssetUuidService::ZFS_DATTO_UUID_PROPERTY, $this->uuid);
        $this->mount();
    }

    /**
     * @inheritDoc
     */
    public function destroy(bool $preserveDataset = false): void
    {
        $this->unmount();
        parent::destroy($preserveDataset);
    }

    /**
     * @return string Filesystem type of the share, e.g. ext4
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * @param string $format Filesystem type of the share, e.g. ext4
     */
    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    /**
     * @return bool
     */
    public function isBackupAclsEnabled(): bool
    {
        return $this->backupAcls;
    }

    /**
     * @param bool $backupAcls
     */
    public function setBackupAcls(bool $backupAcls): void
    {
        $this->backupAcls = $backupAcls;
    }

    /**
     * @return SambaMount
     */
    public function getSambaMount(): SambaMount
    {
        return $this->sambaMount;
    }

    /**
     * Update the samba information for this share.
     *
     * @param SambaMount $mount
     */
    public function setSambaMount(SambaMount $mount): void
    {
        $this->sambaMount = $mount;
    }

    public function getNtlmAuthentication(): ?string
    {
        return $this->ntlmAuthentication;
    }

    public function setNtlmAuthentication(?string $ntlmAuthentication): void
    {
        $this->ntlmAuthentication = $ntlmAuthentication;
    }

    public function getSmbVersion(): ?string
    {
        return $this->smbVersion;
    }

    public function setSmbVersion(?string $smbVersion): void
    {
        $this->smbVersion = $smbVersion;
    }

    /**
     * Copy the configuration from the passed backend to this one.
     *
     * @param Asset $from
     */
    public function copyFrom(Asset $from): void
    {
        parent::copyFrom($from);
    }
}
