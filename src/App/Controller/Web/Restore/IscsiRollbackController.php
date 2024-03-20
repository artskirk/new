<?php

namespace Datto\App\Controller\Web\Restore;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\AssetService;
use Datto\Common\Resource\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for iSCSI Rollback page
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class IscsiRollbackController extends AbstractBaseController
{
    private AssetService $assetService;

    public function __construct(
        NetworkService $networkService,
        AssetService $assetService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->assetService = $assetService;
    }

    /**
     * Render initial iSCSI rollback page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_ISCSI_ROLLBACK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_ISCSI_ROLLBACK_READ")
     *
     * @param string $assetKey The asset key name
     * @param int $snapshot The snapshot timestamp
     * @return Response
     */
    public function indexAction(string $assetKey, int $snapshot): Response
    {
        $asset = $this->assetService->get($assetKey);

        return $this->render(
            'Restore/Iscsi/rollback.html.twig',
            [
                'asset' => $asset,
                'snapshotPoint' => $snapshot
            ]
        );
    }
}
