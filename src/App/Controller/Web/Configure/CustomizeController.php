<?php

namespace Datto\App\Controller\Web\Configure;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Datto\Util\BackgroundImageService;

/**
 * Controller for background customization page
 *
 * @author Andrew Cope <acope@datto.com>
 */
class CustomizeController extends AbstractBaseController
{
    private BackgroundImageService $backgroundImageService;

    public function __construct(
        NetworkService $networkService,
        BackgroundImageService $backgroundImageService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->backgroundImageService = $backgroundImageService;
    }

    /**
     * Render the main index page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_CUSTOM_BACKGROUND")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CUSTOM_BACKGROUND")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $backgroundList = $this->backgroundImageService->getAll();
        return $this->render(
            'Configure/Customize/index.html.twig',
            array(
                'backgroundList' => $backgroundList
            )
        );
    }
}
