<?php

namespace Datto\App\Controller\Api\V1\Device\Restore;

use Datto\License\KrollService;

/**
 * This class contains the API endpoints for downloading Kroll license files to the device.
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
class Kroll
{
    /** @var KrollService */
    private KrollService $krollService;

    public function __construct(
        KrollService $krollService
    ) {
        $this->krollService = $krollService;
    }

    /**
     * Download the Exchange/SharePoint/SqlServer license to the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_GRANULAR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_GRANULAR_WRITE")
     * @return bool True on success.
     */
    public function downloadLicense(): bool
    {
        $this->krollService->logAndDownloadLicense();
        return true;
    }
}
