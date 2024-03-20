<?php

namespace Datto\App\Controller\Web\Migration;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\System\Storage\StorageDevice;
use Datto\System\Storage\StorageService;
use Datto\Util\DateTimeZoneService;
use Datto\ZFS\ZpoolService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles device migrations.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class MigrationController extends AbstractBaseController
{
    private StorageService $storageService;
    private DateTimeZoneService $timezoneService;
    private ZpoolService $zpoolService;

    public function __construct(
        NetworkService $networkService,
        StorageService $storageService,
        DateTimeZoneService $timezoneService,
        ZpoolService $zpoolService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->storageService = $storageService;
        $this->timezoneService = $timezoneService;
        $this->zpoolService = $zpoolService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_STORAGE_UPGRADE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_UPGRADE")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction(): Response
    {
        $homeUrl = $this->generateUrl('home');

        $timezone = $this->timezoneService->getTimeZone();

        $parameters = [
            'timezone' => $timezone,
            'timezoneAbbreviation' => $this->timezoneService->abbreviateTimeZone($timezone),
            'devices' => $this->getDevicesAsArray(),
            'raid' => $this->getRaidLevel(),
            'urls' => [
                'success' => $homeUrl,
                'close' => $homeUrl
            ]
        ];

        return $this->render('Migration/Migrate/index.html.twig', $parameters);
    }

    /**
     * Get all storage devices as an array.
     *
     * @return array
     */
    private function getDevicesAsArray(): array
    {
        $toArray = function (StorageDevice $device) {
            return $device->toArray();
        };

        return array_map($toArray, $this->storageService->getPhysicalDevices());
    }

    /**
     * Gets the raid level of the device
     *
     * @return string
     */
    private function getRaidLevel(): string
    {
        return $this->zpoolService->getRaidLevel(ZpoolService::HOMEPOOL);
    }
}
