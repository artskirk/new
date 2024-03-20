<?php

namespace Datto\App\Controller\Web\Assets;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Log\LogService;
use Datto\Asset\AssetService;
use Datto\Asset\Share\Share;
use Datto\Common\Resource\Filesystem;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handle requests to show an asset's local logs
 *
 * @author Christopher Bitler <cbitler@datto.com>
 */
class LogsController extends AbstractBaseController
{
    const AGENT_RETURN_POINT = 'agents';
    const SHARE_RETURN_POINT = 'shares';

    private LogService $logService;
    private AssetService $assetService;

    public function __construct(
        NetworkService $networkService,
        LogService $logService,
        AssetService $assetService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->logService = $logService;
        $this->assetService = $assetService;
    }

    /**
     * Render the index of the asset's log page
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     *
     * @param string $assetKey
     * @return Response Rendered response of the asset log page
     */
    public function indexAction(string $assetKey): Response
    {
        $asset = $this->assetService->get($assetKey);

        // Since this is a generalized asset page, we need to determine where to go back to
        $returnPoint = self::AGENT_RETURN_POINT;
        if ($asset instanceof Share) {
            $returnPoint = self::SHARE_RETURN_POINT;
        }

        $logRecords = $this->logService->getLocalDescending($asset);
        $arrayRecords = [];
        foreach ($logRecords as $record) {
            $arrayRecords[] = [
                'timestamp' => $record->getTimestamp(),
                'message' => $record->getMessage(),
            ];
        }

        return $this->render('Assets/Logs/index.html.twig', array(
            'hostname' => $asset->getDisplayName(),
            'logs' => $arrayRecords,
            'returnPoint' => $returnPoint
        ));
    }
}
