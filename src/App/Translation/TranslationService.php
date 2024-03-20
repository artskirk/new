<?php

declare(strict_types=1);

namespace Datto\App\Translation;

use Datto\Common\Utility\Filesystem;
use Datto\Resource\DateTimeService;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Service for handling client-side translations.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class TranslationService
{
    private const TRANSLATION_FILE_FORMAT = '%s/messages.%s.yml';
    private const THEME_TRANSLATION_FILE_FORMAT = '%s/%smessages.%s.yml';

    private Environment $twig;
    private TranslatorInterface $translator;
    private Filesystem $filesystem;
    private Router $router;
    private string $translationDir;
    private DateTimeService $dateService;

    public function __construct(
        string $translationDir,
        Environment $twig,
        TranslatorInterface $translator,
        Filesystem $filesystem,
        Router $router,
        DateTimeService $dateService
    ) {
        $this->translationDir = $translationDir;
        $this->twig = $twig;
        $this->translator = $translator;
        $this->filesystem = $filesystem;
        $this->router = $router;
        $this->dateService = $dateService;
    }

    /**
     * Generate the URL for client-side translations.
     *
     * @param string $locale
     * @return string
     */
    public function generateUrl(string $locale): string
    {
        $url = $this->router->generate('translations', ['locale' => $locale]);
        $modifiedAt = (string)$this->getModifiedAt($locale);

        return $url . '?' . $modifiedAt;
    }

    /**
     * Render translations into a format that is consumable client-side.
     *
     * @param string $locale
     * @param string $themeKey
     * @return string
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function render(string $locale, string $themeKey): string
    {
        /** @psalm-suppress UndefinedInterfaceMethod getCatalogue is defined in Symfony\Component\Translation\TranslatorBagInterface */
        $allMessages = $this->translator->getCatalogue($locale)->all();

        $themeFile = 'messages';

        return $this->twig->render($themeKey . '/Default/translations.js.twig', [
            'messages' => $allMessages[$themeFile]
        ]);
    }

    /**
     * Get the modified time of the translations file.
     *
     * @param string $locale
     * @return int
     */
    public function getModifiedAt(string $locale): int
    {
        $translationFile = $this->getTranslationFile($locale);

        if ($this->filesystem->exists($translationFile)) {
            $modifiedAt = $this->filesystem->fileMTime($translationFile);
            if ($modifiedAt) {
                return $modifiedAt;
            }
        }

        return $this->dateService->now()->getTimestamp();
    }

    public function getTranslationFile(?string $locale = null): string
    {
        if (!$locale) {
            $locale = $this->translator->getLocale();
        }

        return sprintf(self::TRANSLATION_FILE_FORMAT, $this->translationDir, $locale);
    }

    public function getThemeTranslationFile(string $themeKey, ?string $locale = null): string
    {
        if (!$locale) {
            $locale = $this->translator->getLocale();
        }

        return sprintf(self::THEME_TRANSLATION_FILE_FORMAT, $this->translationDir, $themeKey, $locale);
    }
}
