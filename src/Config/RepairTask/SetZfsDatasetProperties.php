<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Core\Storage\SirisStorage;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Storage\Zfs;

/** Ensure the desired ZFS properties are set on all volumes */
class SetZfsDatasetProperties implements ConfigRepairTaskInterface
{
    use LoggerAwareTrait;

    private const DATASET_PROPERTIES = [
        'overlay' => 'off',
    ];

    private Zfs $zfs;

    public function __construct(Zfs $zfs)
    {
        $this->zfs = $zfs;
    }

    /** @inheritDoc */
    public function run(): bool
    {
        $success = true;

        foreach (self::DATASET_PROPERTIES as $key => $value) {
            try {
                $this->zfs->inheritProperty(SirisStorage::PRIMARY_POOL_NAME, $key, true);
                $this->zfs->setProperty(SirisStorage::PRIMARY_POOL_NAME, $key, $value);
            } catch (\Throwable $t) {
                $this->logger->error(
                    'ZFS3000 Failed to set ZFS property',
                    ['propertyName' => $key, 'propertyValue' => $value, 'exception' => $t]
                );
                $success = false;
            }
        }

        return $success;
    }
}
