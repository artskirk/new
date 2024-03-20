<?php

namespace Datto\App\Controller\Api\V1\Device\Customize;

use Datto\Util\Email\CustomEmailAlerts\CustomEmailAlertsService;

/**
 * API endpoint to customize the device's email alerts
 *
 * @author Andrew Cope <acope@datto.com>
 */
class Alerts
{
    /** @var CustomEmailAlertsService */
    private $customEmailAlertsService;

    public function __construct(
        CustomEmailAlertsService $customEmailAlertsService
    ) {
        $this->customEmailAlertsService = $customEmailAlertsService;
    }

    /**
     * Attempts to save the email section/subject
     *
     * FIXME This should be combined with v1/device/emails
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_WRITE")
     * @param string $section the section to modify (e.g. screenshots)
     * @param string $subject the subject to save (e.g. "Screenshots for -agentIP")
     */
    public function save($section, $subject): void
    {
        $this->customEmailAlertsService->setSubject($section, $subject);
    }
}
