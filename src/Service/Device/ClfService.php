<?php

declare(strict_types=1);

namespace Datto\Service\Device;

use Datto\App\Translation\TranslationService;
use Datto\AppKernel;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;

class ClfService
{
    private Filesystem $filesystem;
    private DeviceConfig $deviceConfig;
    private TranslationService $translationService;

    private const CACHED_TRANSLATIONS = "*/translations/*";
    private const CLF_THEME_KEY = 'commonlookandfeel';
    private const TRANSLATION_CLF_THEME_KEY = 'clf';
    public const VINTAGE_THEME_KEY = 'vintage';
    private const CONFIG_KEY_NAME = 'disableclf';
    private const ENABLED_CLF_VALUE = null;
    private const DISABLED_CLF_VALUE = 1;

    public function __construct(
        Filesystem $filesystem,
        DeviceConfig $deviceConfig,
        TranslationService $translationService
    ) {
        $this->filesystem = $filesystem;
        $this->deviceConfig = $deviceConfig;
        $this->translationService = $translationService;
    }

    /**
     * @param bool $enableClf - enable or disable CLF
     */
    public function toggleClf(bool $enableClf): void
    {
        if ($enableClf) {
            $value = self::ENABLED_CLF_VALUE;
        } else {
            $value = self::DISABLED_CLF_VALUE;
        }
        $this->deviceConfig->set(self::CONFIG_KEY_NAME, $value);

        $themeFile = $this->translationService->getThemeTranslationFile($this->getTranslationKey());
        $translationFile = $this->translationService->getTranslationFile();
        $this->filesystem->unlinkIfExists($translationFile);
        $this->filesystem->symlink($themeFile, $translationFile);

        $this->clearCachedTranslations();
    }

    public function isClfEnabled(): bool
    {
        return !$this->deviceConfig->has(self::CONFIG_KEY_NAME);
    }

    private function getTranslationKey(): string
    {
        if ($this->isClfEnabled()) {
            return self::TRANSLATION_CLF_THEME_KEY;
        }

        return self::VINTAGE_THEME_KEY;
    }

    public function getThemeKey(): string
    {
        if ($this->isClfEnabled()) {
            return self::CLF_THEME_KEY;
        }

        return self::VINTAGE_THEME_KEY;
    }

    /**
     * When enabling or disabling clf, it is necessary to clear all cached translations
     */
    private function clearCachedTranslations(): void
    {
        $filesToUnlink = $this->filesystem->glob(sprintf(
            AppKernel::DEFAULT_CACHE_DIR_FORMAT,
            self::CACHED_TRANSLATIONS
        ));
        if (is_array($filesToUnlink)) {
            foreach ($filesToUnlink as $fileToUnlink) {
                $this->filesystem->unlink($fileToUnlink);
            }
        }
    }
}
