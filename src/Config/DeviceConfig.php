<?php

namespace Datto\Config;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Verification\Screenshot\ScreenshotAlertService;

/**
 * Access device configuration settings
 * Only config data should be stored/accessed here. For state, use DeviceState.php
 */
class DeviceConfig extends FileConfig
{
    const KEY_DEVICE_ID = 'deviceID';
    const KEY_RESELLER_ID = 'resellerID';
    const KEY_SECRET_KEY = 'secretKey';
    const KEY_IS_SNAPNAS = 'isSnapNAS';
    const KEY_IS_ALTO = 'isAlto';
    const KEY_IS_ALTOXL = 'isAltoXL';
    const KEY_IS_S_LITE = 'isSLite';
    /** @deprecated */
    const KEY_IS_LIGHT = 'isLight';
    /** @deprecated */
    const KEY_S_LIGHT = 'sLight';
    /** @deprecated */
    const KEY_IS_S_LIGHT = 'isSlight';
    /** @deprecated */
    const KEY_IS_LITE = 'isLite';
    /** @deprecated */
    const KEY_S_LITE = 'sLite';
    const KEY_IS_CLOUD_DEVICE = 'isCloudDevice';
    const KEY_REPLICATE_TO_CLOUD_DEVICE = 'replicateToCloudDevice';
    const KEY_SERVICE_PLAN_INFO = 'servicePlanInfo';
    const KEY_HARDWARE_MODEL = 'hardwareModel';
    const KEY_DISPLAY_MODEL = 'model';
    const KEY_DISABLE_TRACKING = 'disableTracking';
    const KEY_IS_VIRTUAL = 'isVirtual';
    const KEY_IMAGE_VER = 'imageVer';
    const KEY_ENABLE_METRICS = 'enableMetrics';
    const KEY_INHIBIT_ALL_CRON = 'inhibitAllCron';
    const KEY_SSH_LOCK_STATUS = "sshLockStatus";
    const KEY_ENABLE_BACKUP_INSIGHTS = 'enableBackupInsights';
    const KEY_ENABLE_RT_NG_NAS = 'enableRTNGNAS';
    const KEY_ENABLE_RT_NG_NAS_SSH = 'enableRTNGNASSsh';
    const KEY_DISABLE_CERT_EXPIRATION_WARNING = 'disableCertExpirationWarning';
    const KEY_DISABLE_SHADOWSNAP_PAIRING = 'disableShadowsnapPairing';
    const KEY_IPMI_ROTATE_ADMIN_PASSWORD = 'ipmiRotateAdminPassword';
    const KEY_PAGINATION_SETTINGS = 'pagination_settings.json';
    const KEY_ENABLE_UVM_LOCAL_VIRT = 'enableUvmLocalVirt';
    const KEY_OS2_VERSION = 'os2Version';
    const KEY_IS_AZURE_DEVICE = "isAzureDevice";
    const KEY_REMOTE_WEB_FORCE_LOGIN = 'remoteWebForceLogin';
    const KEY_FORCE_REMOTE_ADMIN_UPGRADE = 'forceRemoteAdminUpgrade';
    const KEY_SERIAL = 'serial';
    const KEY_DEPLOYMENT_ENVIRONMENT = 'deploymentEnvironment';
    const KEY_DEPLOYMENT_GROUP = 'deploymentGroup';
    const KEY_BANDWIDTH_SCHEDULE = 'bandwidthSchedule';
    const KEY_DEVICE_ORIGINATION = 'deviceOrigination';
    const KEY_IMAGING_DATE = 'imagingDate';
    const KEY_NO_ALTER_ROOT_PASS = 'noAlterRootPass';
    const KEY_STORAGE_EXPAND_THRESHOLD = 'storageExpandThreshold';
    const KEY_DATACENTER_REGION = 'datacenterRegion';
    const KEY_SCREENSHOT_TIMEOUT = 'screenshot.timeout';
    const KEY_ENABLE_DTC_MULTIVOLUME = 'enableDTCMultivolume';
    const KEY_USE_HYPER_SHUTTLE = 'useHyperShuttle';
    const KEY_SKIP_VERIFICATION= 'enableSkipVerification';
    const KEY_ENABLE_SMB_MINIMUM_VERSION_ONE = 'enableSMBMinimumVersionOne';
    const KEY_PREVENT_NETWORK_HOPPING = 'disablePreventNetworkHopping';
    const KEY_CLOUD_MANAGED_CONFIG = 'enableCloudManagedConfig';
    const KEY_DISABLE_TCP_CONNECTION_LIMITING = 'disableTCPConnectionLimiting';
    const KEY_DISABLE_RESTRICTIVE_FIREWALL = 'disableRestrictiveFirewall';
    const KEY_SMB_SIGNING_REQUIRED = 'smbSigningRequired';
    const MODEL_ALTO3A2000 = 'L3A2000';
    const MODEL_ALTO3A2 = 'L3A2';
    const MODEL_ALTO4 = 'ALTO4';
    const MODEL_AZURE = 'SRV0002';
    const MODEL_SIRIS_DIRECT = 'SRV0003';
    const BASE_CONFIG_PATH = "/datto/config";
    const DEV_DEPLOYMENT_ENVIRONMENT = 'dev';
    const RC_DEPLOYMENT_ENVIRONMENT = 'rc';
    const PROD_DEPLOYMENT_ENVIRONMENT = 'prod';
    const VALID_DEPLOYMENT_ENVIRONMENTS = [
        self::DEV_DEPLOYMENT_ENVIRONMENT,
        self::RC_DEPLOYMENT_ENVIRONMENT,
        self::PROD_DEPLOYMENT_ENVIRONMENT
    ];

    public function __construct(Filesystem $filesystem = null)
    {
        parent::__construct(self::BASE_CONFIG_PATH, $filesystem ?: new Filesystem(new ProcessFactory()));
    }

    /**
     * Set a keyfile, always resulting in the keyfile existing, even if its' value evaluates to "empty"
     * (Empty values include 0, null, or an empty string.)
     *
     * @param string $key the key to set
     * @param mixed $data the value to set it to
     * @deprecated Please use touch() or set()
     */
    public function setAllowingEmptyData($key, $data): void
    {
        $this->setRaw($key, $data);
    }

    /**
     * Gets the display model for the device. If either the billing ID or hardware model are not present, use model.
     * ABSOLUTELY FOR DISPLAY PURPOSES ONLY.
     *
     * @return string
     */
    public function getDisplayModel()
    {
        $servicePlanInfo = json_decode($this->get(static::KEY_SERVICE_PLAN_INFO), true);
        $billingID = $servicePlanInfo['servicePlanShortCode'];
        $hardwareModel = $this->get(static::KEY_HARDWARE_MODEL);
        if ($billingID && $hardwareModel) {
            $displayModel = $billingID . '-' . $hardwareModel;
        } else {
            $displayModel = $this->get(static::KEY_DISPLAY_MODEL);
        }
        return $displayModel;
    }

    /**
     * @return string
     */
    public function getBasePath()
    {
        return static::BASE_CONFIG_PATH . '/';
    }

    /**
     * @return bool Return true if Alto device
     */
    public function isAlto()
    {
        return $this->has(self::KEY_IS_ALTO);
    }

    /**
     * @return bool Return true if AltoXL device
     */
    public function isAltoXL()
    {
        return $this->has(self::KEY_IS_ALTOXL);
    }

    /**
     * @return bool True if the device is an Alto 3; false otherwise.
     */
    public function isAlto3(): bool
    {
        $model = $this->get('model');
        return $model === self::MODEL_ALTO3A2
            || $model === self::MODEL_ALTO3A2000;
    }

    /**
     * @return bool True if the device is an Alto 4; false otherwise.
     */
    public function isAlto4(): bool
    {
        $model = $this->get('model');
        return $model === self::MODEL_ALTO4;
    }

    /**
     * @return bool true if SnapNAS device.
     */
    public function isSnapNAS()
    {
        return $this->has(self::KEY_IS_SNAPNAS);
    }

    /**
     * Return the unix timestamp of the device born time.
     *
     * @return int
     */
    public function getDeviceBornTime()
    {
        return strtotime(trim($this->get("born")));
    }

    /**
     * @return bool Return true if Siris Lite device
     */
    public function isSirisLite()
    {
        return $this->has(self::KEY_IS_S_LITE);
    }

    /**
     * @return string Current PHP version
     */
    public function getPhpVersion()
    {
        return phpversion();
    }

    /**
     * @return bool true if screenshot is disabled, false otherwise
     */
    public function isScreenshotDisabled()
    {
        $isScreenshotDisabled = $this->has(ScreenshotAlertService::DISABLE_SCREENSHOTS_KEY);

        return $isScreenshotDisabled;
    }

    public function isCloudDevice(): bool
    {
        return $this->has(self::KEY_IS_CLOUD_DEVICE);
    }

    public function isAzureDevice(): bool
    {
        return $this->has(self::KEY_IS_AZURE_DEVICE);
    }

    public function isAzureModel(): bool
    {
        return $this->get(self::KEY_DISPLAY_MODEL) === self::MODEL_AZURE;
    }

    public function isSirisDirectModel(): bool
    {
        return $this->get(self::KEY_DISPLAY_MODEL) === self::MODEL_SIRIS_DIRECT;
    }

    public function replicatesToCloudDevice(): bool
    {
        return $this->has(self::KEY_REPLICATE_TO_CLOUD_DEVICE);
    }

    public function isVirtual(): bool
    {
        return $this->has(self::KEY_IS_VIRTUAL);
    }

    /**
     * Get this device's ID
     *
     * @return int
     */
    public function getDeviceId()
    {
        return $this->get(self::KEY_DEVICE_ID, null);
    }

    /**
     * Get this device's secret key
     * @return string
     */
    public function getSecretKey(): string
    {
        return trim($this->get(self::KEY_SECRET_KEY, ''));
    }

    /**
     * Get this device's reseller ID
     *
     * @return int
     */
    public function getResellerId()
    {
        return $this->get(self::KEY_RESELLER_ID, null);
    }

    /**
     * Get this device's image version.
     *
     * @return int|null
     */
    public function getImageVersion()
    {
        $image = $this->get(self::KEY_IMAGE_VER, null);
        return $image !== null ? (int)$image : null;
    }

    /**
     * @return string|null The version of os2 installed on the device
     */
    public function getOs2Version()
    {
        return $this->getRaw(self::KEY_OS2_VERSION, null);
    }

    /**
     * Get this device's role.
     *
     * @return string
     */
    public function getRole()
    {
        if ($this->isAzureDevice()) {
            return 'azure';
        } elseif ($this->isCloudDevice()) {
            return 'cloud';
        } else {
            return 'partner';
        }
    }

    /**
     * Get the device's deployment environment.
     * Default is prod deployment environment
     *
     * @return string Deployment environment: dev, rc, or prod
     */
    public function getDeploymentEnvironment(): string
    {
        $environment = self::PROD_DEPLOYMENT_ENVIRONMENT;

        if ($this->has(self::KEY_DEPLOYMENT_ENVIRONMENT)) {
            $envFromFile = strtolower($this->get(self::KEY_DEPLOYMENT_ENVIRONMENT, $environment));
            if (in_array($envFromFile, self::VALID_DEPLOYMENT_ENVIRONMENTS)) {
                $environment = $envFromFile;
            }
        }
        return $environment;
    }

    /**
     * True if the device was imaged by the partner through the GUI or if a vSIRIS. False if the device was imaged
     * through the CLI.
     */
    public function isConverted(): bool
    {
        return $this->get(self::KEY_DEVICE_ORIGINATION, 'datto') == 'converted';
    }

    public function getStorageExpandThreshold(): string
    {
        return $this->get(self::KEY_STORAGE_EXPAND_THRESHOLD, '');
    }

    public function setStorageExpandThreshold(int $threshold): void
    {
        $this->set(self::KEY_STORAGE_EXPAND_THRESHOLD, $threshold);
    }

    /**
     * Test if configuration contains datacenterRegion key.
     *
     * @return bool True if configuration collection contains datacenter region property, otherwise false.
     */
    public function hasDatacenterRegion(): bool
    {
        return $this->has(self::KEY_DATACENTER_REGION);
    }

    /**
     * Retrieve the geographic region associated with an Azure device.
     *
     * @return string Datacenter region.  Empty string if no IMDS data found, or not an Azure device.
     */
    public function getDatacenterRegion(): string
    {
        return $this->get(self::KEY_DATACENTER_REGION, '');
    }

    /**
     * Set the datacenter region name for a device.
     *
     * @param string $regionName Name of region.  Should not be empty.
     * @return void
     */
    public function setDatacenterRegion(string $regionName)
    {
        $this->set(DeviceConfig::KEY_DATACENTER_REGION, $regionName);
    }
}
