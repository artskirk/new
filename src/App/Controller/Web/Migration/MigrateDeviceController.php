<?php

namespace Datto\App\Controller\Web\Migration;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\System\Migration\Device\DeviceMigrationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles device migrations.
 *
 * @author Chris LaRosa <clarosa@datto.com>
 */
class MigrateDeviceController extends AbstractBaseController
{
    private DeviceMigrationService $deviceMigrationService;

    public function __construct(
        NetworkService $networkService,
        DeviceMigrationService $deviceMigrationService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->deviceMigrationService = $deviceMigrationService;
    }

    /**
     * Display the Device Migration Wizard.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     *
     * @param string $option
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction(string $option): Response
    {
        $advancedStatusUrl = $this->generateUrl('advanced_status_index');

        $deviceIsClean = $this->deviceMigrationService->isDeviceClean();

        $parameters = [
            'urls' => [
                'success' => $advancedStatusUrl,
                'close' => $advancedStatusUrl
            ],
            'allowMigrateDeviceConfig' => $deviceIsClean,
            'migrateDeviceConfigByDefault' => $deviceIsClean,
            'migrateSharesByDefault' => $deviceIsClean
        ];

        return $this->render('Migration/MigrateDevice/index.html.twig', $parameters);
    }
}
