<?php

namespace Datto\Util;

use DateTimeZone;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Config\ServerNameConfig;
use Datto\Curl\CurlHelper;
use Datto\Device\Serial;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\System\Php\PhpConfigurationWriter;
use Exception;

/**
 * Wrapper for basic date and timezone functions.
 *
 * @author John Roland <jroland@datto.com>
 */
class DateTimeZoneService
{
    // File path constants
    const LOCALTIME_FILE = '/etc/localtime';
    const TIMEZONE_FILE = '/etc/timezone';
    const DEFAULT_TIMEZONE_FILE = '/usr/share/zoneinfo/America/New_York';
    const TIMEZONE_INFO_BASE_PATH = '/usr/share/zoneinfo';

    /** @var  Filesystem */
    private $filesystem;

    /** @var  ServerNameConfig */
    private $serverNameConfig;

    /** @var CurlHelper */
    private $curl;

    /** @var DeviceConfig */
    private $config;

    private DeviceLoggerInterface $logger;
    private Serial $serial;
    private PhpConfigurationWriter $phpConfigurationWriter;

    public function __construct(
        Filesystem $filesystem = null,
        ServerNameConfig $serverNameConfig = null,
        CurlHelper $curl = null,
        DeviceConfig $config = null,
        PhpConfigurationWriter $phpConfigurationWriter = null,
        DeviceLoggerInterface $logger = null,
        Serial $serial = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->serverNameConfig = $serverNameConfig ?: new ServerNameConfig();
        $this->curl = $curl ?: new CurlHelper();
        $this->config = $config ?: new DeviceConfig();
        $this->phpConfigurationWriter = $phpConfigurationWriter ?: new PhpConfigurationWriter($this->filesystem, new IniTranslator());
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->serial = $serial ?? new Serial();
    }

    /**
     * Get a list of time zones supported by the system's zoneinfo database
     *
     * @return array
     */
    public function readTimeZones()
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * Get the current time zone
     *
     * Look up which file /etc/localtime is pointing to in the zoneinfo database.
     *
     * @return string|bool
     */
    public function getTimeZone()
    {
        if (!$this->filesystem->exists(self::LOCALTIME_FILE)) {
            $this->filesystem->symlink(self::DEFAULT_TIMEZONE_FILE, self::LOCALTIME_FILE);
        } elseif (!$this->filesystem->isLink(self::LOCALTIME_FILE)) {
            try {
                $this->setLocalTime();
            } catch (Exception $e) {
                return false;
            }
        }

        $tz = substr($this->filesystem->readlink(self::LOCALTIME_FILE), 20);
        if (!$tz) {
            return false;
        }

        // Update settings from prior versions, or default to UTC for invalid settings
        // Once the database is free of any of the old settings, we can remove this
        if (!in_array($tz, $this->readTimeZones())) {
            switch ($tz) {
                case 'US/Eastern':
                    $tz = 'America/New_York';
                    break;

                case 'US/Central':
                    $tz = 'America/Chicago';
                    break;

                case 'US/Mountain':
                    $tz = 'America/Shiprock';
                    break;

                case 'US/Pacific':
                    $tz = 'America/Los_Angeles';
                    break;

                case 'US/Alaska':
                    $tz = 'America/Anchorage';
                    break;

                case 'US/Hawaii':
                    $tz = 'Pacific/Honolulu';
                    break;

                case 'GMT':
                default:
                    $tz = 'UTC';
            }
            $this->setTimeZone($tz);
        }

        return $tz;
    }

    /**
     * Set the time zone
     * Note that php-fpm will not pick up on the updated default timezone without restarting php-fpm.
     *
     * @param string $timezone
     *   The timezone that you'd like to set. This must be a valid zoneinfo entry.
     *
     * @return bool
     *   TRUE if it successfully set the time zone, otherwise FALSE.
     */
    public function setTimeZone($timezone)
    {
        $timezones = $this->readTimeZones();

        if (!in_array($timezone, $timezones)) {
            $this->logger->error('DTZ0001 Timezone requested is not in list of readTimeZones', ['timezone' => $timezone]);
            return false;
        }

        $result = $this->filesystem->unlink(self::LOCALTIME_FILE);
        if (!$result) {
            $this->logger->error('DTZ0002 Error removing file', ['file' => self::LOCALTIME_FILE]);
            return false;
        }

        if (!$this->filesystem->symlink(self::TIMEZONE_INFO_BASE_PATH . '/' . $timezone, self::LOCALTIME_FILE)) {
            $this->logger->error('DTZ0003 Could not create symlink', [
                'target' => self::TIMEZONE_INFO_BASE_PATH . '/' . $timezone,
                'link' => self::LOCALTIME_FILE
            ]);
            return false;
        }

        $this->filesystem->filePutContents(self::TIMEZONE_FILE, "$timezone\n");

        $this->updateDeviceWeb($timezone);
        $this->phpConfigurationWriter->setDefaultDateTimeZone($timezone);

        return true;
    }

    /**
     * Generate a date format string based on region
     *
     * Generates a format string for the date() function depending on the system's
     * time zone, in order to match regional convention. The arrays at the beginning
     * of the function define Datto's standard date formats. This function should
     * be used any time that you are outputting a time or date that will be visible
     * to end-users.
     *
     * @param string $format
     *   A string containing the identifier for the format you want. This must be
     *     a valid zoneinfo entry.
     *
     * @return string
     *   A string containing the format to be used in a date() call.
     */
    public function localizedDateFormat($format = 'date-time')
    {
        $formatsDMY = array(                                // Day-first date formats
            'date' => 'j/n/Y',             // 31/1/2012
            'date-short' => 'j/n/y',             // 31/1/12
            'date-long' => 'j F Y',             // 31 January 2012
            'date-time' => 'j/n/y - g:i:s a',   // 31/1/12 - 12:15:30 am
            'date-time-short' => 'j/n/y g:ia',        // 31/1/12 12:15am
            'time' => 'g:i:s a',           // 12:15:30 am
            'time-short' => 'g:ia',              // 12:15am
            'time-date' => 'g:i:s a - j/n/y',   // 12:15:30 am - 31/1/12
            'time-date-short' => 'g:ia j/n/y',        // 12:15am 31/1/12
            'time-date-hyphenated' => 'H-i-s-j-M-y',       // 00-15-30-31-Jan-12
            'time-day-date' => 'g:ia l j/n/Y'       // 12:15am Tuesday 31/1/2012
        );

        $formatsMDY = array(                                // Month-first date formats
            'date' => 'n/j/Y',             // 1/31/2012
            'date-short' => 'n/j/y',             // 1/31/12
            'date-long' => 'F j, Y',            // January 31, 2012
            'date-time' => 'n/j/y - g:i:s a',   // 1/31/12 - 12:15:30 am
            'date-time-short' => 'n/j/y g:ia',        // 1/31/12 12:15am
            'time' => 'g:i:s a',           // 12:15:30 am
            'time-short' => 'g:ia',              // 12:15am
            'time-date' => 'g:i:s a - n/j/y',   // 12:15:30 am - 1/31/12
            'time-date-short' => 'g:ia n/j/y',        // 12:15am 1/31/12
            'time-date-hyphenated' => 'H-i-s-M-j-y',       // 00-15-30-Jan-31-12
            'time-day-date' => 'g:ia l n/j/Y'       // 12:15am Tuesday 1/31/2012
        );

        $mdyTimezones = array(
            // This array was created according to the table found here:
            // http://en.wikipedia.org/wiki/Date_format_by_country

            // The Bahamas
            'America/Nassau',
            // Belize
            'America/Belize',
            // Canada
            'America/Atikokan',
            'America/Blanc-Sablon',
            'America/Cambridge_Bay',
            'America/Dawson',
            'America/Dawson_Creek',
            'America/Edmonton',
            'America/Glace_Bay',
            'America/Goose_Bay',
            'America/Halifax',
            'America/Inuvik',
            'America/Iqaluit',
            'America/Moncton',
            'America/Nipigon',
            'America/Montreal',
            'America/Pangnirtung',
            'America/Rainy_River',
            'America/Rankin_Inlet',
            'America/Regina',
            'America/Resolute',
            'America/St_Johns',
            'America/Swift_Current',
            'America/Thunder_Bay',
            'America/Toronto',
            'America/Vancouver',
            'America/Whitehorse',
            'America/Winnipeg',
            'America/Yellowknife',
            // Federated States of Micronesia
            'Pacific/Chuuk',
            'Pacific/Kosrae',
            'Pacific/Pohnpei',
            'Pacific/Ponape',            // deprecated - replaced by Pacific/Pohnpei
            'Pacific/Truk',                // deprecated - replaced by Pacific/Chuuk
            // Palau
            'Pacific/Palau',
            // Philippines
            'Asia/Manila',
            // USA
            'America/Adak',
            'America/Anchorage',
            'America/Boise',
            'America/Chicago',
            'America/Denver',
            'America/Detroit',
            'America/Indiana/Indianapolis',
            'America/Indiana/Knox',
            'America/Indiana/Marengo',
            'America/Indiana/Petersburg',
            'America/Indiana/Tell_City',
            'America/Indiana/Vevay',
            'America/Indiana/Vincennes',
            'America/Indiana/Winamac',
            'America/Juneau',
            'America/Kentucky/Louisville',
            'America/Kentucky/Monticello',
            'America/Los_Angeles',
            'America/Menominee',
            'America/New_York',
            'America/Nome',
            'America/North_Dakota/Center',
            'America/North_Dakota/New_Salem',
            'America/Phoenix',
            'America/Puerto_Rico',
            'America/Shiprock',
            'America/Yakutat',
            'Pacific/Honolulu',
            'Pacific/Johnston',
            'Pacific/Midway',
            'Pacific/Wake'
        );

        $tz = $this->getTimeZone();

        if (in_array($tz, $mdyTimezones)) {
            return $formatsMDY[$format];
        }

        return $formatsDMY[$format];
    }

    /**
     * Returns a PHP date format string for use device wide and across all locales.
     *
     * @param string $format
     *   A string containing the identifier for the format you want.
     *
     * @return string
     */
    public function universalDateFormat(string $format = 'date-time'): string
    {
        $formats = [
            'date' => 'j/M/Y',                      // 31/Jan/2012
            'date-short' => 'j/M/y',                // 31/Jan/12
            'date-long' => 'j F Y',                 // 31 January 2012
            'date-time' => 'j/M/y - g:i:s a',       // 31/Jan/12 - 12:15:30 am
            'date-time-short' => 'j/M/y g:ia',      // 31/Jan/12 12:15am
            'date-time-short-tz' => 'j/M/y g:ia T', // 31/Jan/12 12:15am CET
            'date-time-long' => 'j F Y g:i:s a',      // 31 January 2012 00:15:30 am
            'time' => 'g:i:s a',                    // 12:15:30 am
            'time-short' => 'g:ia',                 // 12:15am
            'time-date' => 'g:i:s a - j/M/y',       // 12:15:30 am - 31/Jan/12
            'time-date-short' => 'g:ia j/M/y',      // 12:15am 31/Jan/12
            'time-date-long' => 'H:i:s j/M/Y',      // 00:15:30 31/Jan/2012
            'time-date-hyphenated' => 'H-i-s-j-M-y',// 00-15-30-31-Jan-12
            'time-day-date' => 'g:ia l j/M/Y'       // 12:15am Tuesday 31/Jan/2012
        ];

        return $formats[$format];
    }

    /**
     * Abbreviate the specified time zone.
     *
     * @param string $timezone the time zone to abbreviate
     * @return string the abreviated time zone
     */
    public function abbreviateTimeZone($timezone)
    {
        $dateTime = new \DateTime();
        $dateTime->setTimezone(new \DateTimeZone($timezone));
        return $dateTime->format('T');
    }

    private function setLocalTime()
    {
        if ($this->filesystem->exists(self::TIMEZONE_FILE)) {
            $tz = trim($this->filesystem->fileGetContents(self::TIMEZONE_FILE));
            if ($this->filesystem->exists(self::TIMEZONE_INFO_BASE_PATH . '/' . $tz)) {
                $this->filesystem->unlink(self::LOCALTIME_FILE);
                $this->filesystem->symlink(self::TIMEZONE_INFO_BASE_PATH . '/' . $tz, self::LOCALTIME_FILE);
            } else {
                throw new Exception('Timezone file does not exist: ' . self::TIMEZONE_INFO_BASE_PATH . '/' . $tz);
            }
        } else {
            throw new Exception('Timezone file does not exist: ' . self::TIMEZONE_FILE);
        }
    }

    private function updateDeviceWeb($timezone)
    {
        $serial = $this->serial->get();
        $deviceWeb = $this->serverNameConfig->getServer(ServerNameConfig::DEVICE_DATTOBACKUP_COM);
        $secretKey = $this->config->get("secretKey");

        $url = 'https://' . $deviceWeb . '/deviceUpdatehandler.php?'
            . 'action=timezone&'
            . 'tz=' . $timezone . '&'
            . 'mac=' . $serial . '&'
            . 'secretKey=' . $secretKey;

        $this->curl->get($url);
    }
}
