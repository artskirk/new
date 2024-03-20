<?php

namespace Datto\App\Controller\Web\Restore;

use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Controller to redirect "create restore" requests to the appropriate route
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class CreateRestoreRedirectController extends AbstractController
{
    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_WRITE")
     *
     * @param string $type
     * @param string $asset
     * @param int $point
     * @param string $hypervisor
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function indexAction(string $type, string $asset, int $point, string $hypervisor = '')
    {
        if ($type === 'fileRestore') {
            return $this->redirectToRoute(
                'restore_file_configure',
                ['assetKeyName' => $asset, 'point' => $point]
            );
        }

        if ($type === 'localVirt') {
            return $this->redirectToRoute(
                'restore_virtualize_configure',
                ['agentKey' => $asset, 'point' => $point, 'hypervisor' => $hypervisor]
            );
        }

        if ($type === 'esxVirt') {
            return $this->redirectToRoute(
                'restore_virtualize_configure',
                ['agentKey' => $asset, 'point' => $point, 'hypervisor' => $hypervisor]
            );
        }

        if ($type === 'esxUpload') {
            return $this->redirectToRoute(
                'esx_upload_hypervisors',
                ['assetKey' => $asset, 'snapshot' => $point]
            );
        }

        if ($type === 'imageExport') {
            return $this->redirectToRoute(
                'restore_exportimage',
                ['agent' => $asset, 'point' => $point]
            );
        }

        if ($type === 'iscsiRestore' || $type === 'fileRestoreWithAcls' || $type === 'volumeRestore') {
            return $this->redirectToRoute(
                'restore_iscsi',
                ['assetKey' => $asset, 'snapshot' => $point]
            );
        }

        if ($type === 'iscsiRollback') {
            return $this->redirectToRoute(
                'restore_iscsi_rollback',
                ['assetKey' => $asset, 'snapshot' => $point]
            );
        }

        throw new Exception('Invalid recovery type');
    }
}
