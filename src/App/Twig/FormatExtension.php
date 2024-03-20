<?php

namespace Datto\App\Twig;

use Datto\Asset\AssetType;
use Datto\Service\Device\ClfService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Provides useful extensions for Twig.
 *
 * Important:
 *   This class has the potential to grow and be misused for all sorts of things.
 *   If this class grows too big, please split it sensibly. If you add
 *   business or device logic in here, we will hunt you down :-)
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class FormatExtension extends AbstractExtension
{
    const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'gif', 'bmp', 'xpm', 'png'];

    const ARCHIVE_EXTENSIONS = ['zip', 'tar', 'gz', 'bz2', 'xz', 'rar'];

    private TranslatorInterface $translator;
    private ClfService $clfService;

    /**
     * FormatExtension constructor.
     *
     * @param TranslatorInterface $translator
     */
    public function __construct(
        TranslatorInterface $translator,
        ClfService $clfService
    ) {
        $this->translator = $translator;
        $this->clfService = $clfService;
    }

    /**
     * Returns a list of filters to add to the existing list.
     *
     * @return TwigFilter[]
     */
    public function getFilters()
    {
        return [
            new TwigFilter('formatBytes', [$this, 'formatBytes']),
            new TwigFilter('formatTimeLapse', [$this, 'formatTimeLapse']),
            new TwigFilter('formatFilesystemPath', [$this, 'formatFilesystemPath']),
            new TwigFilter('highlightMatch', [$this, 'highlightMatch'])
        ];
    }

    /**
     * Returns a list of functions to add to the existing list.
     *
     * @return TwigFunction[]
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('getFileIcon', array($this, 'getFileIcon'))
        ];
    }

    /**
     * Returns the name of the extension.
     * @return string The extension name
     */
    public function getName()
    {
        return 'app.twig.format_extension';
    }

    /**
     * Format bytes into a human readable file size. The conversion
     * is based on 1024 bytes per KB (not 1000!).
     *
     * @param int $bytes Bytes to be transformed
     * @param int $precision Precision to display
     * @param bool $includeUnit Include the unit (KB, MB, GB, TB...)
     * @return string Formatted file size string (e.g 2.12 KB)
     */
    public function formatBytes($bytes, $precision = 2, $includeUnit = true): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $sign = $bytes < 0 ? -1 : 1;

        $bytes = abs($bytes);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);
        $output = round($bytes, $precision);
        $output = $sign * $output;

        if ($includeUnit) {
            return $output . ' ' . $units[$pow];
        } else {
            return strval($output);
        }
    }

    /**
     * @param int $totalSeconds
     * @return string (e.g 1 Day, 2 Days, 1 Hour, 5 Hours)
     */
    public function formatTimeLapse($totalSeconds): string
    {
        $totalMins = (int)($totalSeconds / 60);
        $totalHours = (int)($totalMins / 60);
        $totalDays = (int)($totalHours / 24);

        $spareSecs  = $totalSeconds % 60;
        $spareMins  = $totalMins % 60;
        $spareHours = $totalHours % 24;

        $output = '';
        if ($totalDays > 0) {
            $output .= $totalDays;
            $unit = $totalDays > 1 ?
                'twig.extension.format.timelapse.days' :
                'twig.extension.format.timelapse.day';
        } elseif ($spareHours > 0) {
            $output .= $spareHours;
            $unit = $spareHours > 1 ?
                'twig.extension.format.timelapse.hours' :
                'twig.extension.format.timelapse.hour';
        } elseif ($spareMins > 0) {
            $output .= $spareMins;
            $unit = $spareMins > 1 ?
                'twig.extension.format.timelapse.minutes' :
                'twig.extension.format.timelapse.minute';
        } else {
            $output .= $spareSecs;
            $unit = $spareSecs > 1 ?
                'twig.extension.format.timelapse.seconds' :
                'twig.extension.format.timelapse.second';
        }
        $output .= ' ' . $this->translator->trans($unit);

        return $output;
    }

    /**
     * Converts the linux path to look like the format of the asset's OS.
     *
     * @param string $path
     * @param string $assetType AssetType::assetType
     * @return string
     */
    public function formatFilesystemPath(string $path, string $assetType): string
    {
        if ($assetType === AssetType::WINDOWS_AGENT || $assetType === AssetType::AGENTLESS_WINDOWS) {
            if ($path === '' || $path === '/') {
                return '\\';
            }
            // Tokenize the path, format the drive like C:\, and join the rest with \
            $tokens = explode('/', trim($path, '/'));
            $drive = $tokens[0] . ':\\';
            $folders = array_slice($tokens, 1);
            return $drive . implode('\\', $folders);
        } else {
            return '/' . ltrim($path, '/');
        }
    }

    /**
     * Places a <mark> tag around matching substrings.
     *
     * @param string $haystack
     * @param string $needle
     * @return string
     */
    public function highlightMatch(string $haystack, string $needle): string
    {
        return preg_replace('/(' . $needle . ')/i', '<mark>$1</mark>', $haystack);
    }

    /**
     * Gets an appropriate fontawesome icon based on a filename.
     *
     * @param string $filename
     * @param bool $isDir
     * @param bool $isLink
     * @return string
     */
    public function getFileIcon(string $filename, bool $isDir, bool $isLink): string
    {
        $isClfEnabled = $this->clfService->isClfEnabled();

        if ($isLink) {
            $icon = $isClfEnabled ? 'fa-share-from-square' : 'icon-share';
        } elseif ($isDir) {
            $icon = $isClfEnabled ? 'fa-folder' : 'icon-folder-close';
        } else {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $icon = $isClfEnabled ? 'fa-file-lines' : 'icon-file-text';
            if (in_array($extension, static::IMAGE_EXTENSIONS, true)) {
                $icon = $isClfEnabled ? 'fa-image' : 'icon-picture';
            } elseif (in_array($extension, static::ARCHIVE_EXTENSIONS, true)) {
                $icon = $isClfEnabled ? 'fa-box-archive' : 'icon-archive';
            } elseif ($extension === 'exe') {
                $icon = $isClfEnabled ? 'fa-list' : 'icon-list-alt';
            }
        }

        return $icon;
    }
}
