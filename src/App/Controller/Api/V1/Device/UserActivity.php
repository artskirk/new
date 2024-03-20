<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Resource\DateTimeService;
use Symfony\Component\HttpFoundation\Request;

/**
 * This controller receives requests for user activity on the browser (e.g. key press, mouse down etc.)
 * that are subsequently used for idle session timeout (see SessionTimeoutListener).
 * @author Sachin Shetty <sshetty@datto.com>
 */
class UserActivity
{
    /**
     * @var DateTimeService $dateTimeService
     */
    private $dateTimeService;

    public function __construct(DateTimeService $dateTimeService)
    {
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * Record user activity (e.g. clicks, keypress etc.) to ensure user session is active
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HOME")
     * @param Request $request
     * @return bool
     */
    public function markActive(Request $request)
    {
        $currentTime = $this->dateTimeService->getTime();
        $request->getSession()->set('lastActivity', $currentTime);
        $request->getSession()->save(); // save now to release the session lock as quickly as possible
        return true;
    }

    /**
     * Endpoint for enabling the 'redirect to login page' decision on session timeout
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HOME")
     * @return bool
     */
    public function ping()
    {
        return true;
    }
}
