<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Asset\RecoveryPoint\RecoveryPoints;

/**
 * Serializes the .recoveryPoints file. The file is a new line
 * separated list of snapshots timestamps.
 *
 * Unserializing:
 *   $recoveryPoints = $serializer->unserialize("1460030417\n1460032417");
 *
 * Serializing:
 *   $serializedRecoveryPoints = $serializer->serialize(new RecoveryPoints());
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class LegacyRecoveryPointsSerializer implements Serializer
{
    /**
     * @param RecoveryPoints $recoveryPoints
     * @return string
     */
    public function serialize($recoveryPoints)
    {
        return implode("\n", $recoveryPoints->getAllRecoveryPointTimes());
    }

    /**
     * @param string $serialized Array of strings containing the file contents
     * @return RecoveryPoints
     */
    public function unserialize($serialized)
    {
        $recoveryPoints = new RecoveryPoints();

        $unserialized = explode("\n", $serialized);
        foreach ($unserialized as $recoveryPoint) {
            if (trim($recoveryPoint) !== '') {
                try {
                    $recoveryPoints->add(new RecoveryPoint($recoveryPoint));
                } catch (\Exception $e) {
                } // ensure that in the case of duplicate points (manually modified file) we don't choke the deserialization
            }
        }

        return $recoveryPoints;
    }
}
