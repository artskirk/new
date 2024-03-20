<?php

namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\EncryptionSettings;
use Datto\Asset\Serializer\Serializer;

/**
 * Serialize and unserialize an EncryptionSettings object
 *
 * Unserializing:
 *      $encryptionSettings = $serializer->unserialize(array(
 *          'encryption' => true,
 *          'encryptionTempAccess' => true,
 *          'encryptionKeyStash' => array('...')
 *      ));
 *
 * Serializing:
 *      $erializer->serialize(new EncryptionSettings());
 */
class EncryptionSettingsSerializer implements Serializer
{
    /**
     * Serialize the given object into an array (not a string!).
     *
     * @param EncryptionSettings $encryption object to convert into an array
     *
     * @return array Serialized object
     */
    public function serialize($encryption)
    {
        if ($encryption->isEnabled()) {
            // This needs to be set to an empty string because is_null('') == false
            // Also the file <agent>.encryption is an empty file anyways.
            return array(
                'encryption' => '1',
                'encryptionTempAccess' => $encryption->getTempAccessKey(),
                'encryptionKeyStash' => json_encode($encryption->getKeyStash())
            );
        } else {
            // File will not be created / file will be unlinked
            return array(
                'encryption' => null,
                'encryptionTempAccess' => null,
                'encryptionKeyStash' => null
            );
        }
    }

    /**
     * Create an object from the given array.
     *
     * @param mixed $fileArray
     * @return mixed object built with the array's data
     */
    public function unserialize($fileArray)
    {
        $encrypted = isset($fileArray['encryption']);
        $tempAccess = isset($fileArray['encryptionTempAccess']) ? $fileArray['encryptionTempAccess'] : null;
        $keyStash = isset($fileArray['encryptionKeyStash']) ? $fileArray['encryptionKeyStash'] : null;
        if ($keyStash !== null) {
            $keyStash = json_decode($keyStash, true);
        }

        return new EncryptionSettings($encrypted, $tempAccess, $keyStash);
    }
}
