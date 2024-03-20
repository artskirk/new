<?php

namespace Datto\App\Controller\Web;

use Datto\Common\Resource\Filesystem;
use Datto\Display\Banner\BannerService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basic controller actions to run for every page.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
abstract class AbstractBaseController extends AbstractController
{
    protected NetworkService $networkService;
    protected ClfService $clfService;
    private Filesystem $filesystem;

    public function __construct(
        NetworkService $networkService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        $this->networkService = $networkService;
        $this->clfService = $clfService;
        $this->filesystem = $filesystem;
    }

    /**
     * Override the default render() method of the Controller class to
     * enhance the template parameters with things needed in every template.
     *
     * @param string $view The view name
     * @param array $parameters An array of parameters to pass to the view
     * @param Response $response A response instance
     * @return Response A Response instance
     */
    protected function render(string $view, array $parameters = [], Response $response = null): Response
    {
        if (!isset($parameters['base'])) {
            $parameters['base'] = [];
        }
        // Use the banner cache here because it's too much work to do on page load otherwise
        $parameters['base']['banners'] = BannerService::getCachedBannerArray();
        $parameters['base']['deviceName'] = $this->networkService->getHostname();

        $layout = $this->clfService->getThemeKey();
        if ($this->clfService->isClfEnabled()) {
            // HACK in case this page has not been re-worked for clf yet, override to use vintage
            if (!$this->filesystem->exists("/usr/lib/datto/device/templates/$layout/$view")) {
                $layout = ClfService::VINTAGE_THEME_KEY;
            }
        }
        return parent::render("$layout/$view", $parameters, $response);
    }
}
