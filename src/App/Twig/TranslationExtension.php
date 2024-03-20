<?php

namespace Datto\App\Twig;

use Datto\App\Translation\TranslationService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Provides twig functions for client-side translations.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class TranslationExtension extends AbstractExtension
{
    /** @var TranslationService */
    private $translationService;

    /**
     * @param TranslationService $translationService
     */
    public function __construct(TranslationService $translationService)
    {
        $this->translationService = $translationService;
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('translationUrl', [$this->translationService, 'generateUrl'])
        ];
    }
}
