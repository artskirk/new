<?php

namespace Datto\App\Controller\Web\Assets;

use Datto\Alert\AlertCodeToKnowledgeBaseMapper;
use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\LastErrorAlert;
use Datto\Common\Resource\Filesystem;
use Datto\Malware\RansomwareService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Controller for displaying agent errors.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ErrorController extends AbstractBaseController
{
    private AssetService $assetService;
    private TranslatorInterface $translator;
    private AlertCodeToKnowledgeBaseMapper $alertCodeMapper;

    public function __construct(
        NetworkService $networkService,
        AssetService $agentService,
        TranslatorInterface $translator,
        AlertCodeToKnowledgeBaseMapper $alertCodeMapper,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->assetService = $agentService;
        $this->translator = $translator;
        $this->alertCodeMapper = $alertCodeMapper;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @param string $assetKey
     * @return Response
     */
    public function indexAction(string $assetKey): Response
    {
        $asset = $this->assetService->get($assetKey);
        $lastError = $asset->getLastError();

        if ($asset->isType(AssetType::SHARE)) {
            $this->denyAccessUnlessGranted('PERMISSION_SHARE_READ');
        } elseif ($asset->isType(AssetType::AGENT)) {
            $this->denyAccessUnlessGranted('PERMISSION_AGENT_READ');
        }

        return $this->render('Assets/Error/index.html.twig', [
            'assetKey' => $asset->getKeyName(),
            'assetDisplayName' => $asset->getDisplayName(),
            'lastError' => $lastError ? $lastError->toArray() : null,
            'isRansomware' => $this->isRansomware($lastError),
            'knowledgeBaseUrl' => $this->generateKnowledgeBaseUrl($lastError),
            'redirectUrl' => $this->generateRedirectUrl($asset)
        ]);
    }

    /**
     * @param LastErrorAlert|null $lastError
     * @return bool
     */
    private function isRansomware(LastErrorAlert $lastError = null): bool
    {
        if (!$lastError) {
            return false;
        }

        return $lastError->getCode() === RansomwareService::FOUND_LOG_CODE;
    }

    /**
     * @param Asset $asset
     * @return string
     */
    private function generateRedirectUrl(Asset $asset): string
    {
        if ($asset->isType(AssetType::SHARE)) {
            return $this->generateUrl('shares_index');
        } else {
            return $this->generateUrl('agents_index');
        }
    }

    /**
     * @param LastErrorAlert|null $lastError
     * @return string|null
     */
    private function generateKnowledgeBaseUrl(LastErrorAlert $lastError = null)
    {
        if (!$lastError) {
            return null;
        }

        $knowledgeBaseUrl = $this->translator->trans('common.knowledgebase');


        // If there's a known error code, use that instead for a direct KB match.
        $codeMatch = $this->alertCodeMapper->getSearchQuery($lastError->getCode(), $lastError->getMessage());
        if ($codeMatch) {
            $searchTerms = "\"" . $codeMatch . "\"";
        } else {
            $searchTerms = $lastError->getMessage();
        }

        return $knowledgeBaseUrl . 'global-search/' . urlencode($searchTerms);
    }
}
