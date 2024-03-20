<?php

namespace Datto\Asset\Share;

use Datto\Asset\Asset;
use Datto\Asset\EmailAddressSettings;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Dataset\Dataset;
use Datto\Dataset\ZFS_Dataset;
use Datto\Dataset\ZVolDataset;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Abstract representation of a share. This class is to be overridden by any type of
 * share, e.g. iSCSI or Samba/NAS.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
abstract class Share extends Asset
{
    const DEFAULT_MAX_SIZE = '16T';
    const BASE_ZFS_PATH = 'homePool/home';
    const BASE_CONFIG_PATH = '/datto/config/keys';

    /** @var ZVolDataset|ZFS_Dataset Base dataset the share data is stored in */
    protected $dataset;

    /** @var LastErrorAlert */
    protected $lastError;

    /** @var DeviceLoggerInterface */
    protected $logger;

    public function __construct(
        $name,
        string $keyName,
        $type,
        $dateAdded,
        Dataset $dataset,
        LocalSettings $local,
        OffsiteSettings $offsite,
        EmailAddressSettings $emailAddresses,
        DeviceLoggerInterface $logger,
        LastErrorAlert $lastError = null,
        $uuid = '',
        OriginDevice $originDevice = null,
        string $offsiteTarget = null
    ) {
        parent::__construct(
            $name,
            $keyName,
            $type,
            $dateAdded,
            $local,
            $offsite,
            $emailAddresses,
            $lastError,
            null,
            null,
            $uuid,
            $originDevice,
            $offsiteTarget
        );

        $this->logger = $logger;
        $this->logger->setAssetContext($keyName);
        $this->dataset = $dataset;
    }

    /**
     * Creates a share
     * @param string $size Size of the zvol to create, ex '16T'

     */
    public function create(string $size)
    {
        // Override if necessary
    }

    /**
     * @return ZVolDataset
     */
    public function getDataset()
    {
        return $this->dataset;
    }

    /**
     * Mount the share!
     */
    public function mount()
    {
        $this->dataset->mount($this->getMountPath());
    }

    /**
     * Destroy the backend.
     * @param bool $preserveDataset true if the zfs dataset should not be deleted
     */
    public function destroy(bool $preserveDataset = false)
    {
        if ($this->dataset->exists()) {
            if ($preserveDataset) {
                $this->logger->info('SHR0002 Preserving dataset for share');
            } else {
                $this->logger->info('SHR0003 Destroying dataset for share'); // log code is used by device-web see DWI-2252
                $this->dataset->destroy();
            }
        }
    }

    /**
     * Copy the configuration from the passed backend to this one.
     *
     * @param Asset $from
     */
    public function copyFrom(Asset $from)
    {
        parent::copyFrom($from);
    }

    /**
     * @return string
     */
    public function getDisplayName()
    {
        return $this->getName();
    }

    /**
     * @inheritdoc
     */
    public function getPairName(): string
    {
        return $this->getName();
    }

    /**
     * @return string|null
     */
    public function getMountPath()
    {
        if ($this->getOriginDevice()->isReplicated()) {
            return null;
        }

        return Asset::BASE_MOUNT_PATH . '/' . $this->name;
    }

    /**
     * Unmount the dataset if the zfs path exists
     */
    protected function unmount(): void
    {
        if ($this->dataset->exists()) {
            $this->logger->debug("SHR0006 Unmounting dataset '{$this->dataset->getZfsPath()}'");
            try {
                $this->dataset->unmount();
            } catch (Exception $e) {
                $this->logger->info('SHR0007 Error unmounting dataset', ['dataset' => $this->dataset->getZfsPath(), 'exception' => $e]);
            }
        }
    }
}
