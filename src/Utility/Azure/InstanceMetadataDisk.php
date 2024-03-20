<?php


namespace Datto\Utility\Azure;

use Exception;
use JsonSerializable;

/**
 * Model to wrap the disk object returned from IMDS
 *
 * @author Bryan Ehrlich <behrlich@datto.com>
 */
class InstanceMetadataDisk implements JsonSerializable
{
    const BYTES_PER_GB = 1024 * 1024 * 1024;
    const IMDS_LUN_KEY = 'lun';
    const IMDS_NAME_KEY = 'name';
    const IMDS_DISK_SIZE_KEY = 'diskSizeGB';
    const IMDS_DISK_SIZE_BYTES_KEY = 'diskSizeBytes';

    /** @var string */
    private $name;

    /** @var int */
    private $lunId;

    /** @var int */
    private $diskSizeInBytes;

    public function __construct(string $name, int $lunId, int $diskSizeInBytes)
    {
        $this->name = $name;
        $this->lunId = $lunId;
        $this->diskSizeInBytes = $diskSizeInBytes;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLunId(): int
    {
        return $this->lunId;
    }

    public function getDiskSizeInBytes(): int
    {
        return $this->diskSizeInBytes;
    }

    public static function fromInstanceMetadataResponse(
        array $instanceMetadataResponse
    ): InstanceMetadataDisk {
        if (!array_key_exists(self::IMDS_NAME_KEY, $instanceMetadataResponse)
            || !array_key_exists(self::IMDS_LUN_KEY, $instanceMetadataResponse)
            || !array_key_exists(self::IMDS_DISK_SIZE_KEY, $instanceMetadataResponse)
        ) {
            throw new Exception('Missing expected keys in instance metadata response');
        }

        return new InstanceMetadataDisk(
            $instanceMetadataResponse[self::IMDS_NAME_KEY],
            $instanceMetadataResponse[self::IMDS_LUN_KEY],
            $instanceMetadataResponse[self::IMDS_DISK_SIZE_KEY] * self::BYTES_PER_GB
        );
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            self::IMDS_NAME_KEY => $this->getName(),
            self::IMDS_LUN_KEY => $this->getLunId(),
            self::IMDS_DISK_SIZE_BYTES_KEY => $this->getDiskSizeInBytes()
        ];
    }
}
