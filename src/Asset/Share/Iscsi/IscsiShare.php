<?php

namespace Datto\Asset\Share\Iscsi;

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
use Datto\Iscsi\IscsiTargetException;
use Datto\Iscsi\IscsiTargetNotFoundException;
use Datto\Iscsi\IscsiTarget;
use Datto\Log\DeviceLoggerInterface;

/**
 * Representation of a iSCSI share.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class IscsiShare extends Share
{
    const BLOCK_SIZE_SMALL = 512;
    const BLOCK_SIZE_LARGE = 4096;

    const DEFAULT_BLOCK_SIZE = self::BLOCK_SIZE_SMALL;

    /** @var int */
    private $blockSize;

    /** @var IscsiTarget */
    private $target;

    /** @var ChapSettings */
    private $chapSettings;

    public function __construct(
        $name,
        string $keyName,
        $dateAdded,
        $blockSize,
        ZVolDataset $dataset,
        LocalSettings $local,
        OffsiteSettings $offsite,
        ChapSettings $chapSettings,
        DeviceLoggerInterface $logger,
        LastErrorAlert $lastError = null,
        EmailAddressSettings $emailAddresses = null,
        IscsiTarget $target = null,
        $uuid = '',
        OriginDevice $originDevice = null,
        string $offsiteTarget = null
    ) {
        parent::__construct(
            $name,
            $keyName,
            AssetType::ISCSI_SHARE,
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

        $this->blockSize= $blockSize;
        $this->chapSettings = $chapSettings;
        $this->target = $target ?: new IscsiTarget();
    }

    /**
     * Creates a dev block (ZVol backend), and iSCSI target with one LUN pointed to the dev block
     * @param string $size Size of the zvol to create, ex '16T'
     */
    public function create(string $size)
    {
        if (!$this->target->isIscsiConfigurationRestored()) {
            throw new IscsiTargetException("Can't create share -- share configuration not restored yet.");
        }

        $this->dataset->create($size, null);
        $targetName = $this->target->makeTargetName($this->getName(), '');

        $this->dataset->setAttribute(AssetUuidService::ZFS_DATTO_UUID_PROPERTY, $this->uuid);
        $blockLink = $this->dataset->getBlockLink();

        $this->target->createTarget($targetName);
        $this->target->addLun($targetName, $blockLink, false, false, null, ['block_size=' . $this->blockSize]);
    }

    /**
     * @inheritDoc
     */
    public function destroy(bool $preserveDataset = false)
    {
        $targetName = $this->target->makeTargetName($this->getName(), '');
        try {
            $this->target->deleteTarget($targetName);
            $this->target->writeChanges();
        } catch (IscsiTargetNotFoundException $e) {
            // Target is already gone. All good.
        }

        parent::destroy($preserveDataset);
    }

    /**
     * @return int
     */
    public function getBlockSize()
    {
        return $this->blockSize;
    }

    /**
     * @return string
     */
    public function getTargetName()
    {
        return $this->target->makeTargetName($this->name, '');
    }

    /**
     * @return ChapSettings
     */
    public function getChap()
    {
        return $this->chapSettings;
    }

    /**
     * Copy the configuration from the passed backend to this one.
     *
     * @param Asset $from
     */
    public function copyFrom(Asset $from)
    {
        parent::copyFrom($from);

        if ($from instanceof IscsiShare) {
            $this->getChap()->copyFrom($from->getChap());
        }
    }

    /**
     * Saves iSCSI target info data
     */
    public function commit()
    {
        $this->target->writeChanges();
    }

    /**
     * @inheritdoc
     */
    public function mount()
    {
        // Do nothing. Iscsi shares don't get mounted
    }

    /**
     * @inheritdoc
     */
    public function getMountPath()
    {
        return null; // Iscsi shares don't have mount paths
    }
}
