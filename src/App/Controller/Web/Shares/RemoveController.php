<?php

namespace Datto\App\Controller\Web\Shares;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Share\ShareService;
use \Datto\Common\Resource\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles requests for the remove share page.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class RemoveController extends AbstractBaseController
{
    private ShareService $service;

    public function __construct(
        NetworkService $networkService,
        ShareService $service,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->service = $service;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_DELETE")
     *
     * @param $shareName
     * @return RedirectResponse|Response
     */
    public function indexAction($shareName): Response
    {
        if (!$this->service->exists($shareName)) {
            return $this->redirect($this->generateUrl('shares_index'));
        }

        $share = $this->service->get($shareName);

        return $this->render('Shares/Remove/index.html.twig', [
            'assetKey' => $share->getKeyName(),
            'displayName' => $share->getDisplayName()
        ]);
    }
}
