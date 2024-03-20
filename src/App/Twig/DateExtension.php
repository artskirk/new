<?php

namespace Datto\App\Twig;

use DateInterval;
use DateTimeInterface;
use DateTimeZone;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Datto\Util\DateTimeZoneService;

/**
 * Provides a useful date formatting extension for Twig.
 *
 * @author Erik Wilson <erwilson@datto.com>
 */
class DateExtension extends AbstractExtension
{
    /** @var DateTimeZoneService  */
    private $dateTimeZoneService;

    /**
     * @param DateTimeZoneService $dateTimeZoneService
     */
    public function __construct(DateTimeZoneService $dateTimeZoneService)
    {
        $this->dateTimeZoneService = $dateTimeZoneService;
    }

    /**
     * Returns a list of functions to add to the existing list.
     *
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('getDateFormat', [$this, 'getDateFormat'])
        ];
    }

    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('dateFormatted', [$this, 'formatDate'], ['needs_environment' => true])
        ];
    }

    /**
     * Returns the name of the extension.
     * @return string The extension name
     */
    public function getName()
    {
        return 'app.twig.date_extension';
    }

    /**
     * Gets a PHP date format string.
     *
     * Returns a string for use with the Twig Date filter. Accepts date format
     * names as in DateTimeZoneService::universalDateTime().
     *
     * @param string $format
     * @return string
     */
    public function getDateFormat(string $format = 'date-time'): string
    {
        $format = $this->dateTimeZoneService->universalDateFormat($format);

        return $format;
    }

    /**
     * Provides a filter to easily format a date by the format name. Returns the formatted date
     *
     * @param Environment                           $env
     * @param DateTimeInterface|DateInterval|string $date     2A date
     * @param string                                $format   The target format, null to use the default
     * @param DateTimeZone|string|null|false        $timezone The target timezone, null to use the default, false to leave unchanged
     *
     * @return string The formatted date
     */
    public function formatDate(Environment $env, $date, string $format = 'date-time', $timezone = null): string
    {
        $formatString = $this->dateTimeZoneService->universalDateFormat($format);

        if ($date instanceof DateInterval) {
            return $date->format($formatString);
        }

        /** @psalm-suppress UndefinedFunction twig_date_converter is defined in vendor/twig/twig/src/Extension/CoreExtension.php */
        return twig_date_converter($env, $date, $timezone)->format($formatString);
    }
}
