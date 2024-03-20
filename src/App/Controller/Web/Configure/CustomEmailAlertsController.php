<?php
namespace Datto\App\Controller\Web\Configure;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\Util\Email\CustomEmailAlerts\CustomEmailAlertsService;
use Datto\Feature\FeatureService;

/**
 * @author Peter Salu <psalu@datto.com>
 * @author Andrew Cope <acope@datto.com>
 */
class CustomEmailAlertsController extends AbstractBaseController
{
    private DeviceConfig $deviceConfig;
    private CustomEmailAlertsService $customEmailAlertsService;
    private FeatureService $featureService;

    public function __construct(
        NetworkService $networkService,
        FeatureService $featureService,
        DeviceConfig $deviceConfig,
        CustomEmailAlertsService $customEmailAlertsService,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->featureService = $featureService;
        $this->deviceConfig = $deviceConfig;
        $this->customEmailAlertsService = $customEmailAlertsService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_CUSTOM_EMAILS_WRITE")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $parameters = array(
            'subjects' => $this->getSubjects(),
            'config' => $this->getConfig()
        );

        return $this->render('Configure/Alerts/index.html.twig', $parameters);
    }

    /**
     * @return array
     */
    private function getSubjects()
    {
        return $this->customEmailAlertsService->getSubjects();
    }

    /**
     * @return array
     */
    private function getConfig()
    {
        return array(
            'canScreenshot' => !$this->deviceConfig->isScreenshotDisabled() &&
                $this->featureService->isSupported(FeatureService::FEATURE_VERIFICATIONS),
            'isSnapNas' => $this->deviceConfig->has(DeviceConfig::KEY_IS_SNAPNAS),
            'isSirisLite' => $this->deviceConfig->isSirisLite()
        );
    }
}
