<?php

namespace Datto\Asset\Serializer;

use Datto\Cloud\SpeedSync;

/**
 * @author Matthew Cheman <mcheman@datto.com>
 */
class OffsiteTargetSerializer implements Serializer
{
    /**
     * @param string|null $offsiteTarget
     * @return string
     */
    public function serialize($offsiteTarget)
    {
        return json_encode([
            'offsiteTarget' => $offsiteTarget
        ]);
    }

    /**
     * @param string $serializedOffsiteTarget
     * @return string Device id of offsite target or 'cloud' for offsiting to the datto cloud
     */
    public function unserialize($serializedOffsiteTarget)
    {
        $offsiteTarget = json_decode($serializedOffsiteTarget, true)['offsiteTarget'] ?? SpeedSync::TARGET_CLOUD;

        return $offsiteTarget;
    }
}
