<?php

namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\VirtualizationSettings;
use Datto\Asset\Serializer\Serializer;

/**
 * Class VirtualizationSettingsSerializer: Serializer for Datto\Asset\Agent\VirtualizationSettings
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class VirtualizationSettingsSerializer implements Serializer
{
    const FILE_KEY = 'legacyVM';

    /**
     * @param VirtualizationSettings $virtualizationSettings The object to convert into an array.
     * @return string Serialized virtualization settings
     */
    public function serialize($virtualizationSettings)
    {
        $isLegacy = ($virtualizationSettings->getEnvironment() === VirtualizationSettings::ENVIRONMENT_LEGACY);
        return array(self::FILE_KEY => $isLegacy ? '{}' : null);
    }

    /**
     * @param array $fileArray Array containing serialized objects.
     * @return VirtualizationSettings Virtualization settings object as read from the array. If
     * no such object is present in the array, the default settings will be returned.
     */
    public function unserialize($fileArray)
    {
        $isLegacy = isset($fileArray[self::FILE_KEY]);
        $environment = $isLegacy ?
            VirtualizationSettings::ENVIRONMENT_LEGACY : VirtualizationSettings::ENVIRONMENT_MODERN;

        return new VirtualizationSettings($environment);
    }
}
