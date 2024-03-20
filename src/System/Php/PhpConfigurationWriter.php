<?php

namespace Datto\System\Php;

use Datto\Common\Utility\Filesystem;
use Datto\Util\IniTranslator;

/**
 * This class handles writing configuration files for php
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class PhpConfigurationWriter
{
    private const DEFAULT_PRIORITY = 20;
    private const DEFAULT_TIMEZONE_FILENAME = 'default-timezone';
    private const PHP_FPM_CONF_PATH = '/etc/php/7.4/fpm/conf.d/';
    private const PHP_CLI_CONF_PATH = '/etc/php/7.4/cli/conf.d/';

    private Filesystem $filesystem;
    private IniTranslator $ini;

    public function __construct(Filesystem $filesystem, IniTranslator $ini)
    {
        $this->filesystem = $filesystem;
        $this->ini = $ini;
    }

    /**
     * Converts the config array passed in to an ini string and writes it into php's config directory. A restart
     * of PHP-FPM/PHP-CGI is typically required for any config changes to take effect.
     *
     *      Example call:
     *          $writer->writeConf('custom-max-filesize', array('upload_max_filesize' => '2M'), 10);
     *
     *
     * @param string $name The name of the config file
     * @param array $config The config data in array form
     *          Eg. array( 'date.timezone' => 'America/Regina' )
     *      Note:
     *          A list of directives can be found at http://php.net/manual/en/ini.list.php
     *
     * @param int $priority
     *      An integer specifying the priority of the configuration. If two configs set the same value, then the one
     *      with the higher priority will have president.
     */
    public function writeConf(string $name, array $config, int $priority = PhpConfigurationWriter::DEFAULT_PRIORITY)
    {
        $iniFilename = "$priority-$name.ini";
        $iniContents = $this->ini->stringify($config);

        $this->filesystem->filePutContents(self::PHP_FPM_CONF_PATH . $iniFilename, $iniContents);
        $this->filesystem->filePutContents(self::PHP_CLI_CONF_PATH . $iniFilename, $iniContents);
    }

    /**
     * Creates a new php config file to set the default timezone.
     *
     * @param string $timeZone The default timezone that you would like to set for php
     *      Note:
     *          A list of all valid timezones can be found at http://php.net/manual/en/timezones.php
     */
    public function setDefaultDateTimeZone($timeZone)
    {
        $this->writeConf(self::DEFAULT_TIMEZONE_FILENAME, ['date.timezone' => $timeZone]);
    }
}
