<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Util\Email\MasterEmailListManager;

/**
 * API endpoint to obtain information about device email addresses.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class Emails
{
    /** @var MasterEmailListManager */
    private $masterEmailListManager;

    public function __construct(
        MasterEmailListManager $masterEmailListManager
    ) {
        $this->masterEmailListManager = $masterEmailListManager;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_WRITE")
     * @param bool $forceCacheUpdate Whether or not to force a cache update (relatively expensive)
     * @return array The list of all email addresses associated with the device.
     */
    public function getAllDeviceEmails($forceCacheUpdate = false)
    {
        $emails = $this->masterEmailListManager->getEmailList($forceCacheUpdate);
        return $emails;
    }
}
