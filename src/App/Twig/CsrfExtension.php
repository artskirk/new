<?php

namespace Datto\App\Twig;

use Datto\App\Security\CsrfValidationListener;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension that provides CSRF token related functions.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class CsrfExtension extends AbstractExtension
{
    /** @var CsrfTokenManagerInterface */
    private $csrfTokenManager;

    /**
     * FormatExtension constructor.
     *
     * @param CsrfTokenManagerInterface $csrfTokenManager
     */
    public function __construct(CsrfTokenManagerInterface $csrfTokenManager)
    {
        $this->csrfTokenManager = $csrfTokenManager;
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrfToken', [$this, 'renderCsrfToken']),
            new TwigFunction('csrfTag', [$this, 'renderCsrfInputTag'], ['is_safe' => ['html']]),
            new TwigFunction('csrfTokenId', [$this, 'renderCsrfTokenId'])
        ];
    }

    /**
     * @return string
     */
    public function renderCsrfToken(): string
    {
        return $this->csrfTokenManager->getToken(CsrfValidationListener::CSRF_TOKEN_ID)->getValue();
    }

    /**
     * @return string
     */
    public function renderCsrfTokenId(): string
    {
        return CsrfValidationListener::CSRF_TOKEN_ID;
    }

    /**
     * @return string
     */
    public function renderCsrfInputTag(): string
    {
        return
            '<input type="hidden" name="' . $this->renderCsrfTokenId() . '" value="' . $this->renderCsrfToken() . '">';
    }
}
