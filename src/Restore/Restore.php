<?php

namespace Datto\Restore;

use Datto\Asset\AssetException;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\Asset;
use Datto\Common\Resource\ProcessFactory;
use Datto\ImageExport\ImageType;
use Datto\Util\DateTimeZoneService;
use RuntimeException;

/**
 * Models a restore, and provides a formatted array description for the UI
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 * @author Jason Miesionczek <jmiesionczek@datto.com>
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class Restore implements \JsonSerializable
{
    const OFFSITE_RESTORE_SUFFIXES = ['hybrid-virt'];

    public static $suffixes = array('active', 'esxUpload', 'file', 'usbBmr', 'bmr', 'vm', 'vmShare');

    public static $suffixesWithoutSnapshot = ["usbBmr", "active", "bmr", "screenshot", "verification", "esxUpload"];

    /** @var string */
    protected $assetKey;

    /** @var string */
    private $point;

    /** @var string */
    private $suffix;

    /** @var string */
    private $activationTime;

    /** @var AssetService */
    protected $assetService;

    /** @var array */
    protected $options;

    /** @var string */
    private $html;

    /** @var ProcessFactory */
    protected $processFactory;

    /**
     * @var Asset
     */
    private $assetObject;

    /** @var DateTimeZoneService */
    private $dateTimeZoneService;

    public function __construct(
        string $assetKey,
        string $point,
        string $suffix,
        ?string $activationTime,
        array $options,
        $html,
        AssetService $assetService = null,
        ProcessFactory $processFactory = null,
        DateTimeZoneService $dateTimeZoneService = null
    ) {
        $this->assetKey = $assetKey;
        $this->point = $point;
        $this->suffix = $suffix;
        $this->activationTime = $activationTime;
        $this->options = $options;
        $this->html = $html;
        $this->assetService = $assetService ?? new AssetService();
        $this->processFactory = $processFactory ?? new ProcessFactory();
        $this->dateTimeZoneService = $dateTimeZoneService ?? new DateTimeZoneService();
    }

    /**
     * Get the identifier of the asset (key file name).
     *
     * @return string asset
     */
    public function getAssetKey()
    {
        return $this->assetKey;
    }

    /**
     * @return int|string restore point (snapshot the restore was cloned from)
     */
    public function getPoint()
    {
        return $this->point;
    }

    /**
     * @return string the type of restore
     */
    public function getSuffix()
    {
        return $this->suffix;
    }

    /**
     * @return string creation time, as a unix timestamp
     */
    public function getActivationTime()
    {
        return $this->activationTime;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * @return string
     */
    public function getHtml()
    {
        return $this->html;
    }

    /**
     * @return string combination of asset, point and suffix, used when providing restore data to the UI
     */
    public function getUiKey()
    {
        $suffix = $this->getSuffix();
        $isExport = $suffix === ImageType::VMDK
            || $suffix === ImageType::VMDK_LINKED
            || $suffix === ImageType::VHD
            || $suffix === ImageType::VHDX;

        if ($isExport) {
            $suffix = 'export';
        }

        return $this->getAssetKey().$this->getPoint().$suffix;
    }

    /**
     * @return array snapshot data in the correct format for the UI to use
     */
    public function getUiData()
    {
        return array(
            'agent' => $this->getAssetKey(),
            'hostname' => $this->getHostname(),
            'point' => $this->getPoint(),
            'pointTime' => date("g:ia F jS", $this->getPoint()),
            'activationTime' => $this->getActivationTime(),
            'restore' => $this->getSuffix(),
            'options' => $this->getOptions()
        );
    }

    /**
     * @return string the clone name used by zfs (does not include the full path of the pool it is in)
     */
    public function getCloneName()
    {
        if (!$this->point || in_array($this->suffix, self::$suffixesWithoutSnapshot)) {
            return $this->getAssetKey().'-'.$this->getSuffix();
        } else {
            return $this->getAssetKey().'-'.$this->getPoint().'-'.$this->getSuffix();
        }
    }

    /**
     * Repair a Restore
     */
    public function repair()
    {
        // Do nothing
    }

    /**
     * Determine if the VM for a restore is currently running.
     *
     * @return bool
     */
    public function virtualizationIsRunning()
    {
        $options = $this->getOptions();
        if (isset($options['vmPoweredOn'])) {
            return $options['vmPoweredOn'];
        }
        return false;
    }

    /**
     * Determine whether or not this restore is for a rescue agent VM.
     *
     * @return bool
     */
    public function isRescueVm()
    {
        return $this->suffix === RestoreType::RESCUE;
    }

    /**
     * @return string
     */
    public function getHostname()
    {
        try {
            $asset = $this->getAssetObject();

            return $asset->getDisplayName();
        } catch (AssetException $e) {
        }// restore's source asset is missing (orphan restore?)


        return '';
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->getUiData();
    }

    /**
     * Utility function to quickly obtain actual Asset instance
     *
     * Caches unserialized asset for the lifetime of this object.
     *
     * @return Asset
     * @throws AssetException
     */
    public function getAssetObject()
    {
        if (null === $this->assetObject) {
            $assetKey = $this->getAssetKey();
            $this->assetObject = $this->assetService->get($assetKey);
        }

        return $this->assetObject;
    }

    /**
     * Gets the mount point directory used for file restores.
     * Returns nothing if it's not a file restore.
     *
     * @return string
     * @throws RuntimeException
     * @throws AssetException
     */
    public function getMountDirectory()
    {
        $assetKey = $this->getAssetKey();
        $asset = $this->getAssetObject();
        $path = '';

        if ($this->getSuffix() === RestoreType::FILE) {
            if ($asset->isType(AssetType::AGENT)) {
                $path = sprintf(
                    '%s/%s/%s',
                    Asset::BASE_MOUNT_PATH,
                    $assetKey,
                    date(
                        $this->dateTimeZoneService->localizedDateFormat('time-date-hyphenated'),
                        (int) $this->getPoint()
                    )
                );
            } elseif ($asset->isType(AssetType::SHARE)) {
                $path = sprintf(
                    '%s/%s-%d-%s',
                    Asset::BASE_MOUNT_PATH,
                    $assetKey,
                    (int) $this->getPoint(),
                    $this->getSuffix()
                );
            } else {
                throw new RuntimeException(sprintf(
                    'Unsupported asset type: %s',
                    $asset->getType()
                ));
            }
        }

        return $path;
    }
}
