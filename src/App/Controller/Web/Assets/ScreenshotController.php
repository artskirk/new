<?php

namespace Datto\App\Controller\Web\Assets;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\AssetService;
use Datto\Common\Resource\Filesystem;
use Datto\Screenshot\ScreenshotFile;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Screenshot controller, serves verification screenshots.
 *
 * @author Benjamin Reynolds <breynolds@datto.com>
 */
class ScreenshotController extends AbstractBaseController
{
    private ScreenshotFileRepository $screenshotFileRepository;
    private AssetService $assetService;

    public function __construct(
        NetworkService $networkService,
        AssetService $assetService,
        ScreenshotFileRepository $screenshotFileRepository,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->assetService = $assetService;
        $this->screenshotFileRepository = $screenshotFileRepository;
    }

    /**
     * Returns a given screenshot of the epoch.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_READ")
     *
     * @param string $assetKey
     * @param int $snapshotEpoch
     * @return Response
     */
    public function indexAction(string $assetKey, int $snapshotEpoch): Response
    {
        if (!$this->assetService->exists($assetKey)) {
            throw $this->createNotFoundException("A asset with the given key does not exist: $assetKey");
        }

        $screenshotFiles = $this->screenshotFileRepository->getAllByAssetAndEpoch($assetKey, $snapshotEpoch);

        $screenshotFiles = array_filter(
            $screenshotFiles,
            function (ScreenshotFile $file): bool {
                return $file->getExtension() === ScreenshotFileRepository::EXTENSION_JPG;
            }
        );

        if (count($screenshotFiles) === 0) {
            throw $this->createNotFoundException("A screenshot does not exist for snapshot: $snapshotEpoch");
        }

        return $this->file(reset($screenshotFiles)->getFile(), null, ResponseHeaderBag::DISPOSITION_INLINE);
    }
}
