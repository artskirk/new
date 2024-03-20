<?php

namespace Datto\Asset\Agent\Windows\Serializer;

use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Agent\Windows\VssWriterSettings;

/**
 * Serialize and unserialize a VssWriterSettings object
 *
 * @author Matt Cheman <mcheman@datto.com>
 */
class VssWriterSettingsSerializer implements Serializer
{
    /**
     * @param VssWriterSettings $vssWriterSettings object to convert into an array
     * @return array serialized vss writers and vss excluded writer ids
     */
    public function serialize($vssWriterSettings)
    {
        return [
            'vssWriters' => serialize($vssWriterSettings->getWriters()),
            'vssExclude' => serialize($vssWriterSettings->getExcludedIds())
        ];
    }

    /**
     * @param string[] $fileArray array of all the asset's serialized settings files
     * @return VssWriterSettings
     */
    public function unserialize($fileArray)
    {
        $writers = @unserialize($fileArray['vssWriters'], ['allowed_classes' => false]) ?: array();
        $excludedIds = @unserialize($fileArray['vssExclude'], ['allowed_classes' => false]) ?: array();

        return new VssWriterSettings($writers, $excludedIds);
    }
}
