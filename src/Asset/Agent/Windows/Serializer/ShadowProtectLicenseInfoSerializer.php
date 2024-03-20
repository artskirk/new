<?php

namespace Datto\Asset\Agent\Windows\Serializer;

use Datto\Asset\Serializer\Serializer;

/**
 * Serializer for ShadowProtect license info. Currently the only information contained in this file is an integer
 * representing the last time that the ShadowProtect license was released, and as such, the serializer simply
 * serializes and unserializes an int. The file format, however, is a JSON array so that it can easily be extended to
 * support full object serialization in the future without adjusting the format of the file.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class ShadowProtectLicenseInfoSerializer implements Serializer
{
    /**
     * @param int $lastReleaseTime
     *
     * @return string
     */
    public function serialize($lastReleaseTime)
    {
        return json_encode(array(
            'lastReleaseTime' => $lastReleaseTime
        ));
    }

    /**
     * @param string $serializedInfo
     *
     * @return int
     */
    public function unserialize($serializedInfo)
    {
        $licenseInfo = json_decode($serializedInfo, true);
        $lastReleaseTime = isset($licenseInfo['lastReleaseTime']) ? (int)$licenseInfo['lastReleaseTime'] : 0;

        return $lastReleaseTime;
    }
}
