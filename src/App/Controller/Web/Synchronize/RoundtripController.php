<?php

namespace Datto\App\Controller\Web\Synchronize;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Common\Resource\Filesystem;
use Datto\Config\ConfigBackup;
use Datto\Roundtrip\RoundtripManager;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;

/**
 * Controller for the Roundtrip wizards
 *
 * @author David Desorcie <ddesorcie@datto.com>
 */
class RoundtripController extends AbstractBaseController
{
    private AssetService $assetService;
    private RoundtripManager $roundtripManager;
    private ConfigBackup $configBackup;

    public function __construct(
        NetworkService $networkService,
        AssetService $assetService,
        RoundtripManager $roundtripManager,
        ConfigBackup $configBackup,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->assetService = $assetService;
        $this->roundtripManager = $roundtripManager;
        $this->configBackup = $configBackup;
    }

    /**
     * Returns the synchronize page
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function usbAction()
    {
        $parameters = $this->getCommonParameters();
        $parameters['enclosures'] = $this->getEnclosures();

        return $this->render('Synchronize/Roundtrip/usb.html.twig', $parameters);
    }

    /**
     * Returns the synchronize page
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function nasAction()
    {
        $parameters = $this->getCommonParameters();

        return $this->render('Synchronize/Roundtrip/nas.html.twig', $parameters);
    }

    /**
     * Gets the parameters that are common to both USB and NAS wizards.
     *
     * @return array
     */
    private function getCommonParameters(): array
    {
        return [
            'assets' => $this->getAssets(),
            'configBackupUsedSpace' => $this->configBackup->getUsedSize(),
            'closeUrl' => $this->generateUrl('synchronize'),
            'successUrl' => $this->generateUrl('synchronize'),
        ];
    }

    /**
     * Get a list of asset data
     *
     * @return array
     */
    private function getAssets(): array
    {
        $assets = $this->assetService->getAllLocal(); // Replicated assets can't be roundtripped
        $assetsData = [];
        foreach ($assets as $asset) {
            $assetsData[] = [
                'pairName' => $asset->getPairName(),
                'keyName' => $asset->getKeyName(),
                'hostname' => $asset->getDisplayName(),
                'type' => $asset->getType(),
                'isShare' => $asset->isType(AssetType::SHARE),
                'usedSpace' => $asset->getDataset()->getUsedSize()
            ];
        }
        return $assetsData;
    }

    /**
     * Retrieve a list of connected enclosures.
     *
     * @return array
     */
    private function getEnclosures(): array
    {
        $enclosureData = [];

        $enclosures = $this->roundtripManager->getEnclosures();
        foreach ($enclosures as $enclosure) {
            $enclosureData[] = [
                'id' => $enclosure->getId(),
                'physicalSize' => $enclosure->getPhysicalSize()
            ];
        }

        return $enclosureData;
    }
}
