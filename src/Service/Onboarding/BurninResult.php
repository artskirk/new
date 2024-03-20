<?php

namespace Datto\Service\Onboarding;

use Datto\Resource\DateTimeService;
use Datto\System\Storage\StorageService;
use Datto\Utility\Storage\Zfs;
use Datto\Utility\Storage\Zpool;
use Datto\Utility\Uptime;

class BurninResult
{
    /** @var StorageService */
    private $storageService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var Zpool */
    private $zpool;

    /** @var Zfs */
    private $zfs;

    /** @var Uptime */
    private $uptime;

    public function __construct(
        StorageService $storageService,
        DateTimeService $dateTimeService,
        Zpool $zpool,
        Zfs $zfs,
        Uptime $uptime
    ) {
        $this->storageService = $storageService;
        $this->dateTimeService = $dateTimeService;
        $this->zpool = $zpool;
        $this->zfs = $zfs;
        $this->uptime = $uptime;
    }

    public function get()
    {
        $rebooted = $this->dateTimeService->getTime() > $this->uptime->getBootedAt();

        return [
            'zpool' => [
                'status' => $this->zpool->getStatus('homePool', true),
                'list' => $this->zfs->list('homePool')
            ],
            'disks' => $this->getDisksAsArray(),
            'rebooted' => $rebooted
        ];
    }

    private function getDisksAsArray(): array
    {
        $disksAsArray = [];
        $disks = $this->storageService->getPhysicalDevices();

        foreach ($disks as $disk) {
            $disksAsArray[] = $disk->toArray();
        }

        return $disksAsArray;
    }
}
