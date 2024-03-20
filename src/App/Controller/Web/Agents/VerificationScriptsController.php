<?php

namespace Datto\App\Controller\Web\Agents;

use Datto\Asset\AssetService;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Controller which supports upload of verification script files
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class VerificationScriptsController extends AbstractController
{
    /** @var AssetService */
    private $assetService;

    public function __construct(AssetService $assetService)
    {
        $this->assetService = $assetService;
    }

    /**
     * Saves an uploaded script to the scripts directory for the associated asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentName
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadAction(string $agentName, Request $request)
    {
        try {
            $asset = $this->assetService->get($agentName);
            if (empty($asset)) {
                throw new RuntimeException('Invalid asset');
            }

            $file = $request->files->get('scriptInput');

            if (is_null($file) || !($file instanceof UploadedFile)) {
                throw new RuntimeException('Missing file attachment');
            }

            $asset->getScriptSettings()->saveScript($file);
            $this->assetService->save($asset);

            return new JsonResponse(['success' => true]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
