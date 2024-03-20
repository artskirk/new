<?php

namespace Datto\Asset\Share\Serializer;

use Datto\Asset\AssetType;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Iscsi\Serializer\LegacyIscsiShareSerializer;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\Nas\Serializer\LegacyNasShareSerializer;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Asset\Share\ExternalNas\Serializer\LegacyExternalNasShareSerializer;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareException;
use Datto\Asset\Share\ShareRepository;
use Datto\Asset\Share\Zfs\Serializer\LegacyZfsShareSerializer;
use Datto\Asset\Share\Zfs\ZfsShare;

/**
 * Convert a Share to an array (to be saved in a text file), and vice vera.
 *
 * This class is a generic Share serializer. For now, it only supports NasShare
 * serialization. In the future, it will also be able to serialize/unserialize
 * IscsiShare objects.
 *
 * The class wraps the serialized array into a $fileArray. This is to easily
 * integrate the serializer with the AssetRepository/ShareRepository.
 *
 * Unserializing:
 *   $share = $serializer->unserialize(
 *     'shareInfo' => array(
 *       'type' => 'nas',
 *       'name' => 'share1',
 *       'access' => array(...),
 *       'offsite' => array(...),
 *       ...
 *     )
 *   ));
 *
 * Serializing:
 *   $serializedShare = $serializer->serialize(new NasShare(...));
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LegacyShareSerializer implements Serializer
{
    /** @var LegacyNasShareSerializer */
    private $nasSerializer;

    /** @var LegacyIscsiShareSerializer */
    private $iscsiSerializer;

    /** @var LegacyExternalNasShareSerializer */
    private $externalNasSerializer;

    /** @var LegacyZfsShareSerializer */
    private $zfsSerializer;

    public function __construct(
        LegacyNasShareSerializer $nasSerializer = null,
        LegacyIscsiShareSerializer $iscsiSerializer = null,
        LegacyExternalNasShareSerializer $externalNasSerializer = null,
        LegacyZfsShareSerializer $zfsSerializer = null
    ) {
        $this->nasSerializer = $nasSerializer ?? new LegacyNasShareSerializer();
        $this->iscsiSerializer = $iscsiSerializer ?? new LegacyIscsiShareSerializer();
        $this->externalNasSerializer = $externalNasSerializer ?? new LegacyExternalNasShareSerializer();
        $this->zfsSerializer = $zfsSerializer ?? new LegacyZfsShareSerializer();
    }

    /**
     * @param Share $object share to convert to an array
     * @return array Serialized array, containing the share's data
     */
    public function serialize($object)
    {
        if ($object instanceof NasShare) {
            return $this->nasSerializer->serialize($object);
        } elseif ($object instanceof IscsiShare) {
            return $this->iscsiSerializer->serialize($object);
        } elseif ($object instanceof ExternalNasShare) {
            return $this->externalNasSerializer->serialize($object);
        } elseif ($object instanceof ZfsShare) {
            return $this->zfsSerializer->serialize($object);
        } else {
            throw new ShareException('Cannot create serializer for object of type ' . get_class($object) . '.');
        }
    }

    /**
     * @param array $fileArray
     * @return Share share object based on the array
     */
    public function unserialize($fileArray)
    {
        if (!isset($fileArray[ShareRepository::FILE_EXTENSION])) {
            throw new ShareException('Unable to load share. Invalid contents.');
        }

        $agentInfo = unserialize($fileArray[ShareRepository::FILE_EXTENSION], ['allowed_classes' => false]);

        if (!AssetType::isType(AssetType::SHARE, $agentInfo)) {
            throw new ShareException('Unable to load share. This is not a share.');
        }

        if (AssetType::isType(AssetType::ZFS_SHARE, $agentInfo)) {
            return $this->zfsSerializer->unserialize($fileArray);
        }

        if (AssetType::isType(AssetType::NAS_SHARE, $agentInfo)) {
            return $this->nasSerializer->unserialize($fileArray);
        }

        if (AssetType::isType(AssetType::ISCSI_SHARE, $agentInfo)) {
            return $this->iscsiSerializer->unserialize($fileArray);
        }

        if (AssetType::isType(AssetType::EXTERNAL_NAS_SHARE, $agentInfo)) {
            return $this->externalNasSerializer->unserialize($fileArray);
        }

        throw new ShareException('Unable to load share. Unknown share type.');
    }
}
