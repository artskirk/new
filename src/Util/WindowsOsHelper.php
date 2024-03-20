<?php

namespace Datto\Util;

/**
 * Helper to extract Windows OS information from strings and map versions to name
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class WindowsOsHelper
{
    const ARCHITECTURES = ["x64", "x32", "x86"];
    const VERSION_REGEX = "/\d+\.\d+\.\d+/";

    const REGEX_VERSION_TO_NAME_MAPPING = [
        "/10\.0/" => "Windows 10",
        "/6\.3\.96/" => "Windows 8.1",
        "/6\.2\.92/" => "Windows 8",
        "/6\.1\.76/" => "Windows 7",
        "/6\.0\.60/" => "Windows Vista",
        "/6\.0\.0/" => "Windows 2008 Server",
        "/5\.2\.37/" => "Windows 2003 Server",
        "/5\.1\.26/" => "Windows XP",
        "/5\.0\.21/" => "Windows 2000"
    ];

    const REGEX_NAME_TO_VERSION_MAPPING = [
        "/.*WINDOWS\ +10/" => "10.0",
        "/.*WINDOWS\ +8\.1/" => "6.3.9600",
        "/.*WINDOWS\ +8\.0/" => "6.2.9200",
        "/.*WINDOWS\ +8\..+/" => "6.3.9600",
        "/.*WINDOWS\ +8.*/" => "6.2.9200",
        "/.*WINDOWS\ +SERVER\ +2012.*R2.*/" => "6.3.9600",
        "/.*WINDOWS\ +SERVER\ +2012.*/" => "6.2.9200",
        "/.*WINDOWS\ +SERVER\ +2008.*R2.*/" => "6.1.7601",
        "/.*WINDOWS\ +7.*/" => "6.1.7600",
        "/.*VISTA.*/" => "6.0.6000",
        "/.*WINDOWS\ +SERVER\ +2008.*/" => "6.0.6000",
        "/.*WINDOWS\ +2000.*/" => "5.0.2100",
        "/.*WINDOWS\ +SERVER\ +2003.*/" => "5.2.3700",
        "/.*XP.*/" => "5.1.2600",
    ];

    /** @var string */
    private $os;

    /** @var array */
    private $parts;

    /**
     * Get the Windows version information from the given OS and/or OS version strings
     *
     * @param string $os
     * @param string $osVersion
     * @return array
     */
    public function windowsVersion(string $os, string $osVersion = null)
    {
        $this->os = $os;
        $this->parts = explode(" ", $this->os);

        if (empty($osVersion)) {
            $osVersion = $this->getOsVersionFromOs();
        }
        $this->removeArchitecture();
        $name = $this->getReconstructedName();
        $osVersionParts = explode(".", $osVersion);
        $win = [
            'long' => $name . " " . $osVersion,
            'windows' => $name,
            'version' => $osVersion,
            'servicePack' => '',
            'major' => $osVersionParts[0] ?? '',
            'minor' => $osVersionParts[1] ?? '',
            'build' => $osVersionParts[2] ?? '',
        ];

        return $win;
    }

    /**
     * Get the common OS name from the version
     *
     * @param string $version
     * @return string
     */
    public function lookupCommonWindowNames(string $version): string
    {
        $version = $this->convertVersionPartsToIntegers($version);
        foreach (self::REGEX_VERSION_TO_NAME_MAPPING as $regex => $osName) {
            if (preg_match($regex, $version)) {
                return $osName;
            }
        }
        return 'Windows';
    }

    /**
     * Get the OS version from the OS full name
     *
     * @return string
     */
    private function getOsVersionFromOs(): string
    {
        $osVersion = null;
        foreach ($this->parts as $index => $part) {
            if (preg_match(self::VERSION_REGEX, $part)) {
                $osVersion = trim($part);
                unset($this->parts[$index]);
                break;
            }
        }

        if (!$osVersion) {
            $osVersion = $this->lookupCommonWindowVersions($this->os);
        }

        return $osVersion;
    }

    /**
     * Remove the architecture string from the os parts
     */
    private function removeArchitecture()
    {
        foreach ($this->parts as $index => $part) {
            if (in_array(strtolower($part), self::ARCHITECTURES)) {
                unset($this->parts[$index]);
                break;
            }
        }
    }

    /**
     * Return the reconstructed name
     *
     * @return string
     */
    private function getReconstructedName(): string
    {
        array_walk($this->parts, function (&$part) {
            $part = trim($part);
        });
        $name = trim(implode(" ", $this->parts));
        return $name;
    }

    /**
     * Get the Windows version associated with the OS name
     *
     * @param string $name
     * @return string
     */
    private function lookupCommonWindowVersions(string $name): string
    {
        $name = strtoupper($name);
        foreach (self::REGEX_NAME_TO_VERSION_MAPPING as $regex => $version) {
            if (preg_match($regex, $name)) {
                return $version;
            }
        }
        return '';
    }

    /**
     * Convert each of the parts in the version number to integers
     *
     * @param string $version
     * @return string
     */
    private function convertVersionPartsToIntegers(string $version): string
    {
        $parts = explode(".", $version);
        for ($partIndex = 0; $partIndex < count($parts); $partIndex++) {
            $parts[$partIndex] = (int)$parts[$partIndex];
        }
        $version = implode(".", $parts);
        return $version;
    }
}
