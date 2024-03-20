<?php

namespace Datto\Asset\Share\Iscsi;

use Datto\Asset\EmailAddressSettings;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\Share\Share;
use Datto\Asset\UuidGenerator;
use Datto\Dataset\DatasetFactory;
use Datto\Iscsi\IscsiTarget;
use Datto\Log\DeviceLoggerInterface;

/**
 * Builder for the iSCSI class. The builder uses sensible defaults, but
 * can override all of the NasShare properties and sub-settings objects.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class IscsiShareBuilder
{
    // Asset

    /** @var string */
    private $name;

    /** @var string */
    private $keyName;

    /** @var int */
    private $dateAdded;

    /** @var LocalSettings */
    private $local;

    /** @var OffsiteSettings */
    private $offsite;

    /** @var EmailAddressSettings */
    private $emailAddresses;

    /** @var UuidGenerator */
    private $uuidGenerator;

    /** @var string */
    private $uuid;

    /** @var string|null */
    private $offsiteTarget;

    // IscsiShare

    /** @var int */
    private $blockSize;

    /** @var IscsiTarget */
    private $target;

    /** @var string */
    private $iscsiChapUser;

    /** @var string */
    private $iscsiMutualChapUser;

    /** @var LastErrorAlert */
    private $lastError;

    /** @var OriginDevice */
    private $originDevice;

    /** @var DatasetFactory */
    private $datasetFactory;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct($name, DeviceLoggerInterface $logger, DatasetFactory $datasetFactory = null)
    {
        $this->name = $name;
        $this->logger = $logger;
        $this->datasetFactory = $datasetFactory ?? new DatasetFactory();

        $this->dateAdded = time();
        $this->offsite = new OffsiteSettings();
        $this->emailAddresses = new EmailAddressSettings();
        $this->uuidGenerator = new UuidGenerator();
        $this->uuid = '';
        $this->local = new LocalSettings($name);

        $this->blockSize = IscsiShare::DEFAULT_BLOCK_SIZE;
        $this->target = new IscsiTarget();
        $this->iscsiChapUser = ChapSettings::DEFAULT_USER;
        $this->iscsiMutualChapUser = ChapSettings::DEFAULT_MUTUAL_USER;
        $this->originDevice = new OriginDevice();
    }

    /**
     * @param int $dateAdded
     * @return IscsiShareBuilder
     */
    public function dateAdded($dateAdded)
    {
        $this->dateAdded = $dateAdded;
        return $this;
    }

    /**
     * @param LocalSettings $local
     * @return IscsiShareBuilder
     */
    public function local(LocalSettings $local)
    {
        $this->local = $local;
        return $this;
    }

    /**
     * @param OffsiteSettings $offsite
     * @return IscsiShareBuilder
     */
    public function offsite(OffsiteSettings $offsite)
    {
        $this->offsite = $offsite;
        return $this;
    }

    /**
     * @param int $blockSize
     * @return IscsiShareBuilder
     */
    public function blockSize($blockSize)
    {
        $this->blockSize = $blockSize;
        return $this;
    }

    /**
     * @param EmailAddressSettings $emailAddresses
     * @return IscsiShareBuilder
     */
    public function emailAddresses(EmailAddressSettings $emailAddresses)
    {
        $this->emailAddresses = $emailAddresses;
        return $this;
    }

    /**
     * @param IscsiTarget $target
     * @return IscsiShareBuilder
     */
    public function target($target)
    {
        $this->target = $target;
        return $this;
    }

    /**
     * @param string $iscsiChapUser
     * @return IscsiShareBuilder
     */
    public function chapUser(string $iscsiChapUser): self
    {
        $this->iscsiChapUser = $iscsiChapUser;
        return $this;
    }

    /**
     * @param string $iscsiMutualChapUser
     * @return IscsiShareBuilder
     */
    public function mutualChapUser(string $iscsiMutualChapUser): self
    {
        $this->iscsiMutualChapUser = $iscsiMutualChapUser;
        return $this;
    }

    /**
     * @param LastErrorAlert|null $lastError
     * @return IscsiShareBuilder
     */
    public function lastError($lastError)
    {
        $this->lastError = $lastError;
        return $this;
    }

    /**
     * @param string $uuid
     * @return IscsiShareBuilder
     */
    public function uuid($uuid)
    {
        $this->uuid = $uuid;
        return $this;
    }

    /**
     * @param OriginDevice $originDevice
     * @return IscsiShareBuilder
     */
    public function originDevice($originDevice)
    {
        $this->originDevice = $originDevice;
        return $this;
    }

    /**
     * @param string $keyName
     * @return IscsiShareBuilder
     */
    public function keyName(string $keyName): IscsiShareBuilder
    {
        $this->keyName = $keyName;
        return $this;
    }

    public function offsiteTarget($offsiteTarget): self
    {
        $this->offsiteTarget = $offsiteTarget;
        return $this;
    }

    /**
     * Build and return a new NasShare object
     * @return IscsiShare
     */
    public function build()
    {
        // Generate new UUID if one has not been given
        if (!$this->uuid) {
            $this->uuid = $this->uuidGenerator->get();
        }

        if (!$this->keyName) {
            $this->keyName = $this->uuid;
        }

        // FIXME integrity check does not belong in local settings since it does apply to shares
        $this->local->setIntegrityCheckEnabled(false);
        $share = new IscsiShare(
            $this->name,
            $this->keyName,
            $this->dateAdded,
            $this->blockSize,
            $this->datasetFactory->createZvolDataset(Share::BASE_ZFS_PATH . '/' . $this->keyName),
            $this->local,
            $this->offsite,
            new ChapSettings($this->name, $this->iscsiChapUser, $this->iscsiMutualChapUser, $this->target),
            $this->logger,
            $this->lastError,
            $this->emailAddresses,
            $this->target,
            $this->uuid,
            $this->originDevice,
            $this->offsiteTarget
        );

        return $share;
    }
}
