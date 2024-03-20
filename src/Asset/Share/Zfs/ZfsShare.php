<?php

namespace Datto\Asset\Share\Zfs;

use Datto\Asset\AssetType;
use Datto\Asset\EmailAddressSettings;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\Share\Nas\AccessSettings;
use Datto\Asset\Share\Nas\AfpSettings;
use Datto\Asset\Share\Nas\ApfsSettings;
use Datto\Asset\Share\Nas\NfsSettings;
use Datto\Asset\Share\Nas\SftpSettings;
use Datto\Asset\Share\Nas\UserSettings;
use Datto\Asset\Share\Share;
use Datto\Dataset\ZFS_Dataset;
use Datto\Samba\SambaManager;
use Datto\Log\DeviceLoggerInterface;

/**
 * Representation of a ZFS Share
 *
 * @author Andrew Cope <acope@datto.com>
 */
class ZfsShare extends Share
{
    /** @var AccessSettings */
    private $accessSettings;

    /** @var UserSettings */
    private $userSettings;

    /** @var AfpSettings */
    private $afp;

    /** @var ApfsSettings */
    private $apfs;

    /** @var NfsSettings */
    private $nfs;

    /** @var SftpSettings */
    private $sftp;

    /** @var SambaManager */
    private $sambaManager;

    public function __construct(
        $name,
        $keyName,
        $dateAdded,
        ZFS_Dataset $dataset,
        LocalSettings $local,
        OffsiteSettings $offsite,
        EmailAddressSettings $emailAddresses,
        AccessSettings $accessSettings,
        UserSettings $userSettings,
        AfpSettings $afp,
        ApfsSettings $apfs,
        NfsSettings $nfs,
        SftpSettings $sftp,
        SambaManager $sambaManager,
        DeviceLoggerInterface $logger,
        LastErrorAlert $lastError = null,
        $uuid = '',
        OriginDevice $originDevice = null
    ) {
        parent::__construct(
            $name,
            $keyName,
            AssetType::ZFS_SHARE,
            $dateAdded,
            $dataset,
            $local,
            $offsite,
            $emailAddresses,
            $logger,
            $lastError,
            $uuid,
            $originDevice
        );

        $this->accessSettings = $accessSettings;
        $this->userSettings = $userSettings;
        $this->afp = $afp;
        $this->apfs = $apfs;
        $this->nfs = $nfs;
        $this->sftp = $sftp;
        $this->sambaManager = $sambaManager;
    }

    /**
     * @return array
     */
    public function listUsers(): array
    {
        return $this->userSettings->getAll();
    }

    /**
     * @return AccessSettings
     */
    public function getAccess(): AccessSettings
    {
        return $this->accessSettings;
    }

    /**
     * @return bool
     */
    public function isPublic(): bool
    {
        return $this->accessSettings->getLevel() === AccessSettings::ACCESS_LEVEL_PUBLIC;
    }

    /**
     * @return AfpSettings
     */
    public function getAfp(): AfpSettings
    {
        return $this->afp;
    }

    /**
     * @return ApfsSettings
     */
    public function getApfs(): ApfsSettings
    {
        return $this->apfs;
    }

    /**
     * @return NfsSettings
     */
    public function getNfs(): NfsSettings
    {
        return $this->nfs;
    }


    /**
     * @inheritDoc
     */
    public function destroy(bool $preserveDataset = false)
    {
        // Disable protocols, if they are enabled
        if ($this->afp->isEnabled()) {
            $this->afp->disable();
        }

        if ($this->nfs->isEnabled()) {
            $this->nfs->disable();
        }

        if ($this->sftp->isEnabled()) {
            $this->sftp->disable();
        }

        $this->sambaManager->removeShare($this->getName());

        $this->unmount();
        parent::destroy($preserveDataset);
    }
}
