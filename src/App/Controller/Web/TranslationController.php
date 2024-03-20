<?php

declare(strict_types=1);

namespace Datto\App\Controller\Web;

use Datto\App\Translation\TranslationService;
use Datto\Resource\DateTimeService;
use Datto\Service\Device\ClfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Controller that exposes an endpoint for retrieving translations client-side.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class TranslationController extends AbstractController
{
    private TranslationService $translationService;
    private ClfService $clfService;
    private DateTimeService $dateService;

    public function __construct(
        TranslationService $translationService,
        ClfService $clfService,
        DateTimeService $dateService
    ) {
        $this->translationService = $translationService;
        $this->clfService = $clfService;
        $this->dateService = $dateService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NONE")
     *
     * @param Request $request
     * @return Response
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function indexAction(Request $request): Response
    {
        $locale = $request->getLocale();
        $themeKey = $this->clfService->getThemeKey();
        $contents = $this->translationService->render($locale, $themeKey);
        $lastModified = $this->dateService->fromTimestamp($this->translationService->getModifiedAt($locale));

        $response = new Response($contents, 200, [
            'Content-Type' => 'application/javascript'
        ]);
        $response->prepare($request);
        $response->setImmutable(true);
        $response->setLastModified($lastModified);

        return $response;
    }
}
