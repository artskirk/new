<?php

namespace Datto\App\Controller\Web\Configure;

use Datto\Util\BackgroundImageService;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Controller for upload of custom background image
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class BackgroundController extends AbstractController
{
    /** @var BackgroundImageService */
    private $backgroundImageService;

    public function __construct(BackgroundImageService $backgroundImageService)
    {
        $this->backgroundImageService = $backgroundImageService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_CUSTOM_BACKGROUND")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CUSTOM_BACKGROUND")
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadAction(Request $request)
    {
        try {
            $file = $request->files->get('backgroundInput');

            if (is_null($file) || !($file instanceof UploadedFile)) {
                throw new RuntimeException('Bad upload');
            }

            $result = $this->backgroundImageService->upload($file);
            return new JsonResponse($result);
        } catch (Throwable $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
