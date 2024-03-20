<?php

namespace Datto\Asset\Agent\Backup\Serializer;

use Datto\Asset\Agent\VolumesService;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Agent\Backup\AgentSnapshot;

/**
 * Translates from AgentSnapshot object to array and vice versa.
 *
 * @author Edward Li <eli@datto.com>
 */
class AgentSnapshotSerializer implements Serializer
{
    private OperatingSystemSerializer $operatingSystemSerializer;
    private DiskDriveSerializer $diskDriveSerializer;

    public function __construct(
        OperatingSystemSerializer $operatingSystemSerializer = null,
        DiskDriveSerializer $diskDriveSerializer = null
    ) {
        $this->operatingSystemSerializer = $operatingSystemSerializer ?: new OperatingSystemSerializer();
        $this->diskDriveSerializer = $diskDriveSerializer ?: new DiskDriveSerializer();
    }

    /**
     * @param AgentSnapshot $agentSnapshot
     * @return array
     */
    public function serialize($agentSnapshot)
    {
        $desiredVolumes = $agentSnapshot->getDesiredVolumes();
        $operatingSystem = $agentSnapshot->getOperatingSystem();
        $volumes = $agentSnapshot->getVolumes();
        $protectedVolumes = $agentSnapshot->getProtectedVolumes();
        $diskDrives = $agentSnapshot->getDiskDrives();

        return array(
            "keyName" => $agentSnapshot->getKeyName(),
            "epoch" => $agentSnapshot->getEpoch(),
            "desiredVolumes" => $desiredVolumes
                ? $desiredVolumes->getIncludedList()
                : null,
            "operatingSystem" => $operatingSystem
                ? $this->operatingSystemSerializer->serialize($operatingSystem)
                : null,
            "volumes" => $volumes
                ? $volumes->toArray()
                : null,
            "diskDrives" => $diskDrives
                ? $this->diskDriveSerializer->serialize($diskDrives)
                : null,
            "protectedVolumes" => $protectedVolumes->toArray()
        );
    }

    /**
     * @param array $serializedObject
     * @return AgentSnapshot
     */
    public function unserialize($serializedObject)
    {
        $keyName = $serializedObject["keyName"];
        $epoch = $serializedObject["epoch"];
        $desiredVolumes = $serializedObject["desiredVolumes"];
        $operatingSystem = $serializedObject["operatingSystem"];
        $volumes = $serializedObject["volumes"];
        $diskDrives = $serializedObject["diskDrives"]["disks"];

        return new AgentSnapshot(
            $keyName,
            $epoch,
            $volumes,
            $desiredVolumes,
            $operatingSystem,
            $diskDrives,
            null // Default to repository with $keyName and $epoch
        );
    }
}
