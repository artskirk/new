<?php

namespace Datto\App\Controller\Web\Restore;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetType;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Restore\Export\ExportManager;
use Datto\Restore\Export\Stages\ImageConversionHelper;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;

class ExportImageController extends AbstractBaseController
{
    private ImageConversionHelper $helper;
    private AgentService $agentService;
    private DeviceConfig $deviceConfig;
    private ExportManager $exportManager;

    public function __construct(
        NetworkService $networkService,
        ExportManager $exportManager,
        ImageConversionHelper $helper,
        AgentService $agentService,
        DeviceConfig $deviceConfig,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->exportManager = $exportManager;
        $this->helper = $helper;
        $this->agentService = $agentService;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_IMAGE_EXPORT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_IMAGE_EXPORT_READ")
     *
     * @param string $agent
     * @param string $point
     * @return Response
     */
    public function exportAction(string $agent, string $point): Response
    {
        // Strip the 'local' indicator out of the point in the URL.
        $point = str_ireplace('L', '', $point);
        $agentObject = $this->agentService->get($agent);
        $supportedMedia = $this->getSupportedMedia($agentObject);
        $supportedFormats = $this->helper->getSupportedFormats($agentObject);
        $isAlto = $this->deviceConfig->isAlto();
        $isLinuxAgent =
            $agentObject->isType(AssetType::LINUX_AGENT) || $agentObject->isType(AssetType::AGENTLESS_LINUX);
        $isWindows =
            $agentObject->isType(AssetType::WINDOWS_AGENT) || $agentObject->isType(AssetType::AGENTLESS_WINDOWS) ;
        $isEncrypted = $agentObject->getEncryption()->isEnabled();
        $exportString = $agentObject->getKeyName() . $point . 'export';
        $exportedImage = $this->exportManager->getExportShare($exportString);
        if (array_key_exists('error', $exportedImage)) {
            $exportedImage = null;
        }
        return $this->render(
            'Restore/ExportImage/export.html.twig',
            array(
                'supportedMedia' => $supportedMedia,
                'supportedFormats' => $supportedFormats,
                'isAlto' => $isAlto,
                'isLinuxAgent' => $isLinuxAgent,
                'isWindows' => $isWindows,
                'agentKeyName' => $agentObject->getKeyName(),
                'agentDisplayName' => $agentObject->getDisplayName(),
                'isEncrypted' => $isEncrypted,
                'currentPoint' => $point,
                'exportedImage' => $exportedImage
            )
        );
    }

    /**
     * Get supported media list.
     *
     * @param Agent $agent
     * @return array
     */
    private function getSupportedMedia(Agent $agent): array
    {
        $supportedMedia = [
            'SHARE' => 'share',
            'USB' => 'usb',
        ];

        if ($this->deviceConfig->isAlto() || !$agent->isSupportedOperatingSystem()) {
            $supportedMedia = [
                'SHARE' => 'share',
            ];
        }

        return $supportedMedia;
    }
}
