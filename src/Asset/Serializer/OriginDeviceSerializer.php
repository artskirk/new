<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\OriginDevice;

/**
 * Serialize and unserialize an OriginDevice object to and from its array representation.
 * The .originDevice key file is json encoded and looks like:
 *     {"deviceId":13579,"resellerId":2468,"isReplicated":true,"isOrphaned":false}
 *
 * @author John Roland <jroland@datto.com>
 */
class OriginDeviceSerializer implements Serializer
{
    const FILE_KEY = 'originDevice';

    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param OriginDevice $originDevice object to convert into an array
     * @return array representing the OriginDevice
     */
    public function serialize($originDevice)
    {
        return [
            static::FILE_KEY => json_encode([
                'deviceId' => $originDevice->getDeviceId(),
                'resellerId' => $originDevice->getResellerId(),
                'isReplicated' => $originDevice->isReplicated(),
                'isOrphaned' => $originDevice->isOrphaned()
            ])
        ];
    }

    /**
     * Create an object from the given array.
     *
     * @param array $fileArray array to convert to an object
     * @return OriginDevice
     */
    public function unserialize($fileArray)
    {
        $originSettings = @json_decode($fileArray[static::FILE_KEY], true);

        $deviceId = $originSettings['deviceId'] ?? null;
        $resellerId = $originSettings['resellerId'] ?? null;
        $isReplicated = $originSettings['isReplicated'] ?? false;
        $isOrphaned = $originSettings['isOrphaned'] ?? false;

        return new OriginDevice($deviceId, $resellerId, $isReplicated, $isOrphaned);
    }
}
