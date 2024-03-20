<?php

namespace Datto\Asset\Share\Nas;

use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Asset\AssetUuidService;
use Datto\Asset\EmailAddressSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\LocalSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\Share\Share;
use Datto\Dataset\ZVolDataset;
use Datto\Samba\SambaManager;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Representation of a NAS share.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class NasShare extends Share
{
    const DEFAULT_FORMAT = 'ext4';

    /** @var string Format of the share, e.g. ext4 */
    private $format;

    /** @var SambaManager */
    private $samba;

    /** @var AfpSettings */
    private $afp;

    /** #var NfsSettings */
    private $nfs;

    /** @var SftpSettings */
    private $sftp;

    /** @var UserSettings */
    private $users;

    /** @var AccessSettings */
    private $access;

    /** @var GrowthReportSettings growthReport holds information for the growth report of a share*/
    private $growthReport;

    private ApfsSettings $apfs;

    public function __construct(
        $name,
        string $keyName,
        $dateAdded,
        $format,
        ZVolDataset $dataset,
        SambaManager $sambaManager,
        AccessSettings $access,
        AfpSettings $afp,
        ApfsSettings $apfs,
        NfsSettings $nfs,
        SftpSettings $sftp,
        LocalSettings $local,
        OffsiteSettings $offsite,
        EmailAddressSettings $emailAddresses,
        UserSettings $users,
        DeviceLoggerInterface $logger,
        GrowthReportSettings $growthReport = null,
        LastErrorAlert $lastError = null,
        $uuid = '',
        OriginDevice $originDevice = null,
        string $offsiteTarget = null
    ) {
        parent::__construct(
            $name,
            $keyName,
            AssetType::NAS_SHARE,
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
        $this->samba = $sambaManager;
        $this->access = $access;
        $this->afp = $afp;
        $this->apfs = $apfs;
        $this->nfs = $nfs;
        $this->sftp = $sftp;
        $this->users = $users;
        $this->growthReport = $growthReport ?: new GrowthReportSettings();
    }

    /**
     * Creates a NAS share and save its model.
     *
     * This will first create a parent ZFS dataset, and then a ZVol for the actual share.
     *
     * @param string $size Size of the zvol to create, ex '16T'
     */
    public function create(string $size)
    {
        try {
            $this->dataset->create($size, $this->format);
            $this->dataset->setAttribute(AssetUuidService::ZFS_DATTO_UUID_PROPERTY, $this->uuid);

            $this->mount();

            $sambaShare = $this->samba->createShare($this->name, $this->getMountPath(), $this->getSambaConfigPath());
            $sambaShare->changeACLMode('acl_and_mask');
            $sambaShare->setProperty("read only", "no");
            $sambaShare->setAccess($this->getAccess()->getLevel());
            $this->samba->sync();
        } catch (Throwable $e) {
            $this->logger->error('NAS0001 Failed to create Nas Share', ['error' => $e->getMessage()]);
            $this->destroy();
            throw $e;
        }
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

        if ($this->apfs->isEnabled()) {
            $this->apfs->disable();
        }

        if ($this->nfs->isEnabled()) {
            $this->nfs->disable();
        }

        if ($this->sftp->isEnabled()) {
            $this->sftp->disable();
        }

        $this->samba->removeShare($this->getName());

        $this->unmount();
        parent::destroy($preserveDataset);
    }

    /**
     * @return string Filesystem type of the share, e.g. ext4
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @return AfpSettings
     */
    public function getAfp()
    {
        return $this->afp;
    }

    /**
     * @return ApfsSettings
     */
    public function getApfs()
    {
        return $this->apfs;
    }

    /**
     * @return NfsSettings
     */
    public function getNfs()
    {
        return $this->nfs;
    }

    /**
     * @return SftpSettings
     */
    public function getSftp()
    {
        return $this->sftp;
    }

    /**
     * @return UserSettings
     */
    public function getUsers()
    {
        return $this->users;
    }

    /**
     * @return AccessSettings
     */
    public function getAccess()
    {
        return $this->access;
    }

    /**
     * @return SambaManager
     */
    public function getSamba()
    {
        return $this->samba;
    }

    /**
     * @return GrowthReportSettings
     */
    public function getGrowthReport()
    {
        return $this->growthReport;
    }

    /**
     * Copy the configuration from the passed backend to this one.
     *
     * @param Asset $from
     */
    public function copyFrom(Asset $from)
    {
        parent::copyFrom($from);

        if ($from instanceof NasShare) {
            $this->access->copyFrom($from->access);
            $this->users->copyFrom($from->users); // users must be copied before afp/sftp/nfs settings
            $this->afp->copyFrom($from->afp);
            $this->apfs->copyFrom($from->apfs);
            $this->sftp->copyFrom($from->sftp);
            $this->nfs->copyFrom($from->nfs);
            $this->growthReport->copyFrom($from->growthReport);
        }
    }

    /**
     * Save the Share configuration
     */
    public function commit()
    {
        $this->samba->sync();
    }

    public function mount()
    {
        parent::mount();
        // Checks if protocols need to be enabled - if so enable them
        if ($this->getSftp()->isEnabled()) {
            $this->getSftp()->enable();
        }
    }

    /**
     * @return string samba config file location
     */
    private function getSambaConfigPath()
    {
        return self::BASE_CONFIG_PATH . '/' . $this->getKeyName() . '.samba';
    }
}
