<?php

namespace Datto\Virtualization\Hypervisor\Config;

use Datto\Connection\ConnectionType;
use RuntimeException;

/**
 * Create virtualization settings
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class VmSettingsFactory
{
    private function __construct()
    {
    }

    /**
     * Returns an object with virtualization settings.
     *
     * @param ConnectionType $connectionType
     * @return AbstractVmSettings
     */
    public static function create(ConnectionType $connectionType): AbstractVmSettings
    {
        $settings = null;

        switch ($connectionType) {
            case ConnectionType::LIBVIRT_ESX():
                $settings = new EsxVmSettings();
                break;
            case ConnectionType::LIBVIRT_HV():
                $settings = new HvVmSettings();
                break;
            case ConnectionType::LIBVIRT_KVM():
                $settings = new KvmVmSettings();
                break;
            default:
                throw new RuntimeException(
                    "Unknown connection type was passed. '$connectionType'"
                );
        }

        return $settings;
    }
}
