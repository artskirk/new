<?php

namespace Datto\Feature;

/**
 * Generic feature class that determines whether or not a feature is supported.
 * Can also turn features on/off on an asset or device level.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
abstract class Feature
{
    // Default to all roles. These can be limited by overriding the getRequiredDeviceRoles method.
    const DEVICES_ROLES = [
        DeviceRole::PHYSICAL,
        DeviceRole::VIRTUAL,
        DeviceRole::CLOUD,
        DeviceRole::AZURE
    ];

    /** @var String */
    protected $name;

    /** @var Context */
    protected $context;

    /** @var string */
    protected $version = null;

    /**
     * @param string|null $name
     * @param Context|null $context
     */
    public function __construct(
        string $name = null,
        Context $context = null
    ) {
        $this->name = $name;
        $this->context = $context;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name)
    {
        $this->name = $name;
    }

    /**
     * @param Context $context
     */
    public function setContext(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Whether or not the feature is supported for the current context
     *
     * @param string|null $version
     * @return bool
     */
    public function isSupported($version = null)
    {
        $deviceRoleSupport = $this->checkDeviceRole();
        $deviceSupported = $this->checkDeviceConstraints();
        $assetSupported = $this->context->getAsset() === null || $this->checkAssetConstraints();
        $versionSupported = $this->checkVersion($version);

        return $deviceRoleSupport && $deviceSupported && $assetSupported && $versionSupported;
    }

    /**
     * Check whether the feature is supported for this device's role
     *
     * @return bool
     */
    protected function checkDeviceRole(): bool
    {
        $deviceRoles = $this->getSupportedDeviceRoles();

        // If the base class definition has not been overridden, return true as all device roles are allowed
        if ($deviceRoles === Feature::DEVICES_ROLES) {
            return true;
        }

        $deviceConfig = $this->context->getDeviceConfig();

        if ($deviceConfig->isAzureDevice()) {
            $deviceRole = DeviceRole::AZURE;
        } elseif ($deviceConfig->isCloudDevice()) {
            $deviceRole = DeviceRole::CLOUD;
        } elseif ($deviceConfig->isVirtual()) {
            $deviceRole = DeviceRole::VIRTUAL;
        } else {
            $deviceRole = DeviceRole::PHYSICAL;
        }

        return in_array($deviceRole, $deviceRoles);
    }

    /**
     * Get the list of device roles for which this feature is enabled
     */
    protected function getSupportedDeviceRoles(): array
    {
        return Feature::DEVICES_ROLES;
    }

    /**
     * Check whether or not the feature is supported for the device
     *
     * @return bool
     */
    protected function checkDeviceConstraints()
    {
        // Override if necessary
        return true;
    }

    /**
     * Check whether or not the feature is supported for the asset
     *
     * @return bool
     */
    protected function checkAssetConstraints()
    {
        // Override if necessary
        return true;
    }

    /**
     * Check whether or not the version of the requested software meets the
     * required version. Returns true if no version check is required.
     *
     * @param string $version optional version string
     * @return bool
     */
    protected function checkVersion($version = null)
    {
        return version_compare($version, $this->version) >= 0;
    }
}
