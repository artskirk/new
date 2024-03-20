<?php

namespace Datto\Asset\Agent\Agentless\Serializer;

use Datto\Asset\Agent\Agentless\EsxInfo;
use Datto\Asset\Serializer\Serializer;
use Exception;

/**
 * Unserializes the .esxInfo file
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class LegacyEsxInfoSerializer implements Serializer
{
    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param mixed $object object to convert into an array
     * @return mixed Serialized object
     */
    public function serialize($object)
    {
        throw new Exception('Saving esx info through the serializers is not supported for now');
    }

    /**
     * Create an object from the given array.
     *
     * @param string $serializedEsxInfo
     * @return EsxInfo
     */
    public function unserialize($serializedEsxInfo)
    {
        $esxInfo = unserialize($serializedEsxInfo, ['allowed_classes' => false]);

        return new EsxInfo(
            $esxInfo['name'] ?? '',
            $esxInfo['moRef'] ?? '',
            $esxInfo['totalBytesCopied'] ?? 0,
            (isset($esxInfo['vmdkInfo']) && is_array($esxInfo['vmdkInfo'])) ? $esxInfo['vmdkInfo'] : [],
            $esxInfo['vmx'] ?? '',
            $esxInfo['connectionName'] ?? ''
        );
    }
}
