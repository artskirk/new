<?php

namespace Datto\App\Controller\Api\V1\Device\Storage;

use Datto\ZFS\ZpoolService;

class Scrub
{
    /** @var ZpoolService */
    private $zpoolService;

    public function __construct(ZpoolService $zpoolService)
    {
        $this->zpoolService = $zpoolService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_SCRUB")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SCRUB")
     *
     * @return bool
     */
    public function start(): bool
    {
        $this->zpoolService->startScrub(ZpoolService::HOMEPOOL);

        return true;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_SCRUB")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SCRUB")
     *
     * @return bool
     */
    public function stop(): bool
    {
        $this->zpoolService->cancelScrub(ZpoolService::HOMEPOOL);

        return true;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_SCRUB")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SCRUB")
     *
     * @return array
     */
    public function getStatus(): array
    {
        return $this->zpoolService
            ->getZpoolStatus(ZpoolService::HOMEPOOL)
            ->jsonSerialize();
    }
}
