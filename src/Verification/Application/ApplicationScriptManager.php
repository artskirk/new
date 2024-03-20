<?php

namespace Datto\Verification\Application;

/**
 * Class that manages application detection scripts.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ApplicationScriptManager
{
    const APPLICATION_SCRIPT_TIMEOUT_SECONDS = 70; // Maximum execution time of a application verification script.
    const APPLICATION_MSSQL = 'mssql-server';
    const APPLICATION_DHCP  = 'dhcp-server';
    const APPLICATION_AD_DOMAIN = 'ad-domain';
    const APPLICATION_DNS = 'dns-server';
    const APPLICATION_SCRIPT_MAP = [
        self::APPLICATION_MSSQL => '00_internal_detectSqlServer.ps1',
        self::APPLICATION_DHCP => '00_internal_detectDhcpServer.ps1',
        self::APPLICATION_AD_DOMAIN => '00_internal_detectADDomain.ps1',
        self::APPLICATION_DNS => '00_internal_detectDnsServer.ps1',
    ];

    // Return values of verification scripts
    const ERROR_UNSUPPORTED_VERSION = 'error_unsupported_version';
    const ERROR_SERVICE_NOT_FOUND = 'error_service_not_found';
    const ERROR_SERVICE_TIMEOUT = 'error_service_timeout';
    const ERROR_UNKNOWN = 'error_unknown';

    const NET_START_SERVICE_ENUMERATION_SCRIPT = '00_internal_enumerateServicesNetStart.ps1';
    const GET_SERVICES_SERVICE_ENUMERATION_SCRIPT = '00_internal_enumerateServicesGetServices.ps1';
    // Pre-sleep time should match the "Start-Sleep -s 60" command in the scripts
    const SERVICES_ENUMERATION_SCRIPT_SLEEP_TIME_SECONDS = 60;
    const SERVICES_ENUMERATION_SCRIPT_RUN_TIME_SECONDS = 60;
    const SERVICES_ENUMERATION_SCRIPT_TIMEOUT_SECONDS =
        self::SERVICES_ENUMERATION_SCRIPT_SLEEP_TIME_SECONDS +
        self::SERVICES_ENUMERATION_SCRIPT_RUN_TIME_SECONDS;

    /** @var string */
    private $scriptDir;

    /**
     * @param string $scriptDir
     */
    public function __construct(string $scriptDir)
    {
        $this->scriptDir = $scriptDir;
    }

    /**
     * Get the full path of the specified applications.
     *
     * @param string[] $applicationIds
     * @return string[]
     */
    public function getScriptFilePaths(array $applicationIds): array
    {
        $scriptFilePaths = [];

        foreach ($applicationIds as $applicationId) {
            if (!isset(self::APPLICATION_SCRIPT_MAP[$applicationId])) {
                throw new \Exception('Unknown application: ' . $applicationId);
            }

            $scriptName = self::APPLICATION_SCRIPT_MAP[$applicationId];
            $path = $this->getScriptFilePath($scriptName);
            $scriptFilePaths[$scriptName] = $path;
        }

        return $scriptFilePaths;
    }

    /**
     * Check if a script name is an application detection script.
     *
     * @param $scriptName
     * @return bool
     */
    public function isApplicationScriptName(string $scriptName): bool
    {
        return in_array($scriptName, self::APPLICATION_SCRIPT_MAP, true);
    }

    /**
     * Determine the application name from a given script name.
     *
     * @param string $scriptName
     * @return string
     */
    public function getApplicationIdFromScriptName(string $scriptName): string
    {
        foreach (self::APPLICATION_SCRIPT_MAP as $applicationId => $applicationScriptName) {
            if ($scriptName === $applicationScriptName) {
                return $applicationId;
            }
        }

        throw new \Exception('Could not find application based on script name: ' . $scriptName);
    }

    /**
     * Get the path to the net-start version of the service enumeration script
     *
     * @return string
     */
    public function getNetStartServiceEnumerationScriptPath(): string
    {
        return $this->getScriptFilePath(self::NET_START_SERVICE_ENUMERATION_SCRIPT);
    }

    /**
     * @param string $scriptName
     * @return bool
     */
    public function isNetStartServiceEnumerationScriptName(string $scriptName): bool
    {
        return $scriptName === self::NET_START_SERVICE_ENUMERATION_SCRIPT;
    }

    /**
     * Get the path to the Get-Services version of the service enumeration script
     *
     * @return string
     */
    public function getGetServicesServiceEnumerationScriptPath(): string
    {
        return $this->getScriptFilePath(self::GET_SERVICES_SERVICE_ENUMERATION_SCRIPT);
    }

    /**
     * @param string $scriptName
     * @return bool
     */
    public function isGetServicesServiceEnumerationScriptName(string $scriptName): bool
    {
        return $scriptName === self::GET_SERVICES_SERVICE_ENUMERATION_SCRIPT;
    }

    /**
     * @param string $scriptName
     * @return string
     */
    private function getScriptFilePath(string $scriptName): string
    {
        return $this->scriptDir . $scriptName;
    }
}
