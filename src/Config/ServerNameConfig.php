<?php

namespace Datto\Config;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Util\IniTranslator;

/**
 * Represents the server names of the cloud servers the device is
 * communicating with. Locations are loaded from the countryDefaults.ini
 * file based on the country code.
 */
class ServerNameConfig
{
    const COUNTRY_KEY = 'country';

    const INI_FILE = '/etc/datto/countryDefaults.ini';
    const COUNTRY_FILE = '/datto/config/country';
    const DEFAULT_COUNTRY = 'DEFAULT';

    const DEVICE_DATTOBACKUP_COM = 'DEVICE_DATTOBACKUP_COM';
    const SPEEDTEST_DATTOBACKUP_COM = 'SPEEDTEST_DATTOBACKUP_COM';

    private Filesystem $filesystem;

    private DeviceConfig $deviceConfig;

    private IniTranslator $iniTranslator;

    /** @var string[] */
    private array $serverCache = [];

    public function __construct(
        Filesystem $filesystem = null,
        DeviceConfig $deviceConfig = null,
        IniTranslator $iniTranslator = null
    ) {
        $this->filesystem = $filesystem ?? new Filesystem(new ProcessFactory());
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->iniTranslator = $iniTranslator ?? new IniTranslator();
    }

    /**
     * Returns the instance of a single server
     *
     * @param string $key - Server Key
     *
     * @return mixed - Server address (String) or False (Bool)
     */
    public function getServer(string $key)
    {
        if (empty($this->serverCache)) {
            $this->serverCache = $this->getServersByCountry($this->getCountry());
        }

        return $this->serverCache[$key] ?? null;
    }

    /**
     * Goes through the countryDefaults.ini file and returns an array of servers for the specified country
     */
    public function getServersByCountry(string $country): array
    {
        if (!$this->filesystem->exists(self::INI_FILE)) {
            return $this->getDefaultServers();
        }

        $country = $this->formatCountry($country);
        $iniContents = $this->getParsedCountryDefaults();

        // Overwrite defaults with $country values if present
        return array_merge($iniContents[self::DEFAULT_COUNTRY], $iniContents[$country] ?? []);
    }

    /**
     * Set servers for a specific country.
     */
    public function setServers(string $country, array $servers): void
    {
        if ($this->filesystem->exists(self::INI_FILE)) {
            $countryDefaults = $this->getParsedCountryDefaults();
        } else {
            $countryDefaults = [self::DEFAULT_COUNTRY => $this->getDefaultServers()];
        }

        $countryDefaults[$this->formatCountry($country)] = $servers;

        $this->filesystem->filePutContents(self::INI_FILE, $this->iniTranslator->stringify($countryDefaults));

        $this->serverCache = []; // clear cache to make getServer() reload it the next time it's called
    }

    /**
     * @param string $country
     */
    public function setCountry(string $country): void
    {
        $this->deviceConfig->set(
            self::COUNTRY_KEY,
            $this->formatCountry($country)
        );

        $this->serverCache = []; // clear cache to make getServer() reload it the next time it's called
    }

    /**
     * Looks up the country from the /datto/config/country file
     *
     * @return string Name of the country
     */
    public function getCountry(): string
    {
        if ($this->deviceConfig->has(self::COUNTRY_KEY)) {
            return $this->formatCountry($this->deviceConfig->get(self::COUNTRY_KEY));
        } else {
            return self::DEFAULT_COUNTRY;
        }
    }

    /**
     * Setup all defaults
     */
    private function getDefaultServers(): array
    {
        $defaults = array();

        $defaults["ADMIN_DATTOBACKUP_COM"] = "admin.dattobackup.com";
        $defaults["DATTOBACKUP_COM"] = "dattobackup.com";
        $defaults["DEVICE_DATTOBACKUP_COM"] = "device.dattobackup.com";
        $defaults["SPEEDTEST_DATTOBACKUP_COM"] = "speedtest.dattobackup.com";
        $defaults["TEST22_DATTOBACKUP_COM"] = "test22.dattobackup.com";
        $defaults["TEST80_DATTOBACKUP_COM"] = "test80.dattobackup.com";
        $defaults["TEST443_DATTOBACKUP_COM"] = "test443.dattobackup.com";
        $defaults["BMC_DATTO_COM"] = "bmc.datto.com";

        return $defaults;
    }

    private function getParsedCountryDefaults() : array
    {
        $contents = $this->filesystem->parseIniFile(self::INI_FILE, true);
        if ($contents === false) {
            $contents = [];
        }
        return $contents;
    }

    private function formatCountry(string $country): string
    {
        return strtoupper(trim($country));
    }
}
