<?php

namespace Datto\App\Controller\Web\Registration;

use Datto\Common\Resource\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Registration\ActivationService;
use Datto\System\Storage\StorageDevice;
use Datto\System\Storage\StorageService;
use Datto\Config\DeviceConfig;
use Datto\Service\Registration\RegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RegisterController extends AbstractController
{
    private ActivationService $activationService;
    private RegistrationService $registrationService;
    private DeviceConfig $deviceConfig;
    private StorageService $storageService;
    private Filesystem $filesystem;
    private ClfService $clfService;

    public function __construct(
        ActivationService $activationService,
        RegistrationService $registrationService,
        DeviceConfig $deviceConfig,
        StorageService $storageService,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        $this->activationService = $activationService;
        $this->registrationService = $registrationService;
        $this->deviceConfig = $deviceConfig;
        $this->storageService = $storageService;
        $this->filesystem = $filesystem;
        $this->clfService = $clfService;
    }

    /**
     * Entry point for the device Registration Wizard
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     *
     * @param Request $request Symfony HTTP request
     * @return Response Symfony HTTP response
     */
    public function indexAction(Request $request)
    {
        if ($this->registrationService->isRegistered()) {
            return $this->redirectToRoute('homepage');
        }

        if ($request->query->has('devicetable')) {
            return $this->renderDeviceTable();
        }

        return $this->renderIndex();
    }

    /**
     * Renders and returns the Registration Wizard webpage with required values
     *
     * @return Response Symfony HTTP response
     */
    private function renderIndex()
    {
        $authorizationCode = $this->activationService->getStoredAuthorizationCode();
        // check what device we're on
        $isVirtual = $this->deviceConfig->has('isVirtual');
        $isConverted = $this->deviceConfig->isConverted();

        $authorizationPaneEnabled = $isConverted || $isVirtual;
        $storagePaneEnabled = $isVirtual;
        $storageCapacity = intval($this->deviceConfig->get('capacity'));
        $updatePaneEnabled = !$this->registrationService->wasJustImaged();
        $datacenterCountryMapping = $this->getDatacenterCountryMapping();
        $showDatacenters = $this->registrationService->shouldDisplayDatacenterLocations();

        $view = 'Registration/Register/index.html.twig';
        $layout = $this->getLayout($view);

        return $this->render(
            "$layout/$view",
            [
                'authorizationCode' => $authorizationCode,
                'authorizationPaneEnabled' => $authorizationPaneEnabled,
                'storagePaneEnabled' => $storagePaneEnabled,
                'storageCapacity' => $storageCapacity,
                'updatePaneEnabled' => $updatePaneEnabled,
                'showDatacenters' => $showDatacenters,
                'datacenterCountryMapping' => $datacenterCountryMapping
            ]
        );
    }

    /**
     * Return a mapping between the display name of countries and the name used in the backend.
     * @return array
     */
    public function getDatacenterCountryMapping(): array
    {
        return [
            'registration.register.pane.register.datacenter.australia' => 'Australia',
            'registration.register.pane.register.datacenter.canada' => 'Canada',
            'registration.register.pane.register.datacenter.germany' => 'Germany',
            'registration.register.pane.register.datacenter.iceland' => 'Iceland',
            'registration.register.pane.register.datacenter.singapore' => 'Singapore',
            'registration.register.pane.register.datacenter.uk' => 'United Kingdom',
            'registration.register.pane.register.datacenter.usa' => 'United States'
        ];
    }

    /**
     * Renders and returns the storage device table for the Registration Wizard storage pane
     *
     * @return Response Symfony HTTP response
     */
    private function renderDeviceTable()
    {
        $this->storageService->rescanDevices();
        $devices = $this->storageService->getDevices();

        $storageDevices = [];
        foreach ($devices as $device) {
            if ($device->getStatus() === StorageDevice::STATUS_AVAILABLE) {
                $storageDevices[] = [
                    'capacity' => $device->getCapacity(),
                    'model' => $device->getModel(),
                    'name' => $device->getName()
                ];
            }
        }

        $view = 'Registration/Register/deviceTable.html.twig';
        $layout = $this->getLayout($view);

        return $this->render(
            "$layout/$view",
            [
                'storageDevices' => $storageDevices
            ]
        );
    }

    private function getLayout(string $view): string
    {
        $layout = $this->clfService->getThemeKey();
        if ($this->clfService->isClfEnabled()) {
            // HACK in case this page has not been re-worked for clf yet, override to use vintage
            if (!$this->filesystem->exists("/usr/lib/datto/device/templates/$layout/$view")) {
                $layout = ClfService::VINTAGE_THEME_KEY;
            }
        }

        return $layout;
    }
}
