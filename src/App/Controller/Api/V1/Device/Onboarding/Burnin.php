<?php

namespace Datto\App\Controller\Api\V1\Device\Onboarding;

use Datto\Service\Onboarding\BurninService;

class Burnin
{
    /** @var BurninService */
    private $burninService;

    public function __construct(BurninService $burninService)
    {
        $this->burninService = $burninService;
    }

    /**
     * Start running the burnin process. This may fail if the system has already run burnin or it is not in a
     * state that can handle it (ie. it has assets).
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_BURNIN")
     * @Datto\App\Security\RequiresPermission("PERMISSION_BURNIN")
     *
     * @return bool
     */
    public function start(): bool
    {
        return $this->burninService->start();
    }

    /**
     * Get the status of burnin.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_BURNIN")
     * @Datto\App\Security\RequiresPermission("PERMISSION_BURNIN")
     *
     * @return array
     */
    public function getStatus(): array
    {
        return $this->burninService->getStatus()->toArray();
    }

    /**
     * If the status is "finished", you can get the results of the burnin run.
     *
     * Note: the output here is fairly raw and assumes that the caller has the ability to verify it. We may
     * want to extend this in the future to have the os2 check it itself.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_BURNIN")
     * @Datto\App\Security\RequiresPermission("PERMISSION_BURNIN")
     *
     * @return array
     */
    public function getFinishedResult(): array
    {
        return $this->burninService->getFinishedResult();
    }
}
