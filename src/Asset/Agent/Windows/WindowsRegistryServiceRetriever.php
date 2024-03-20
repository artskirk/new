<?php

namespace Datto\Asset\Agent\Windows;

use Datto\Asset\Agent\Agent;
use Datto\Restore\Windows\Hivex\Commands\FileExportCommand;
use Datto\Restore\Windows\Hivex\Commands\FileMergeCommand;
use Datto\Restore\Windows\Hivex\Hive;
use Datto\Restore\Windows\Hivex\HiveKey;
use Datto\Restore\HIR\Windows\Registry\ServiceStart;
use Datto\Log\DeviceLoggerInterface;

/**
 * Retrieves the list of services from the registry in a Windows snapshot.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class WindowsRegistryServiceRetriever
{
    /**
     * Format string for building the path for the mount point.
     *
     * Given the agent name 'foo', it will produce
     * '/var/lib/datto/inspection/registry/foo'.
     */
    const MOUNT_PATH_FORMAT = '/var/lib/datto/inspection/registry/%s';

    const WIN32_STAND_ALONE_SERVICE = 0x10;
    const WIN32_SHARED_SERVICE = 0x20;

    private WindowsMountedFilesystem $mountedFilesystem;
    private WindowsRegistryLocator $windowsRegistryLocator;
    private FileExportCommand $fileExportCommand;
    private FileMergeCommand $fileMergeCommand;
    private DeviceLoggerInterface $logger;

    public function __construct(
        WindowsMountedFilesystem $mountedFilesystem,
        WindowsRegistryLocator $windowsRegistryLocator,
        FileExportCommand $fileExportCommand,
        FileMergeCommand $fileMergeCommand,
        DeviceLoggerInterface $logger
    ) {
        $this->mountedFilesystem = $mountedFilesystem;
        $this->windowsRegistryLocator = $windowsRegistryLocator;
        $this->fileExportCommand = $fileExportCommand;
        $this->fileMergeCommand = $fileMergeCommand;
        $this->logger = $logger;
    }

    /**
     * Retrieves the list of services for the specified Windows snapshot.
     *
     * @param Agent $agent
     * @param int $snapshotEpoch
     * @return WindowsService[] List of services indexed by windows service ID
     */
    public function retrieveRunningServices(Agent $agent, int $snapshotEpoch): array
    {
        $agentKeyName = $agent->getKeyName();
        $mountPoint = sprintf(self::MOUNT_PATH_FORMAT, $agentKeyName);

        try {
            $this->mountedFilesystem->mountOsDriveFromSnapshot($agent, $snapshotEpoch, $mountPoint);
            $runningServices = $this->retrieveRunningServicesFromMountedOsDrive($mountPoint);
        } finally {
            $this->mountedFilesystem->unmountOsDriveForSnapshot();
        }

        return $runningServices;
    }

    /**
     * Retrieves currently running services in the agent side.
     *
     * @param string $mountPoint Linux filesystem path to the mounted Windows OS drive.
     * @return WindowsService[] List of services indexed by windows service ID
     */
    private function retrieveRunningServicesFromMountedOsDrive(string $mountPoint): array
    {
        $systemHive = new Hive(
            $this->fileExportCommand,
            $this->fileMergeCommand,
            $this->logger,
            $this->windowsRegistryLocator->getSystemHivePath($mountPoint)
        );
        $currentControlSet = $this->getCurrentControlSet($systemHive);
        $systemHive->load("$currentControlSet\\Services", 3);

        // Key names are not case-sensitive in the Windows registry, however
        // our HiveKey class treats them as case-sensitive when used as indexes.
        // We've seen the "Services" key as both capitalized (Windows 10) and
        // all lower-case (Windows 7) so we have to accommodate both cases.
        /** @var HiveKey $servicesHiveKey */
        if (isset($systemHive[$currentControlSet]) && $systemHive[$currentControlSet] instanceof HiveKey) {
            if (isset($systemHive[$currentControlSet]['Services'])) {
                $servicesHiveKey = $systemHive[$currentControlSet]['Services'];
            } elseif (isset($systemHive[$currentControlSet]['services'])) {
                $servicesHiveKey = $systemHive[$currentControlSet]['services'];
            } else {
                $this->logger->error("RSR0011 Can't find Services key in Windows registry.");
                return [];
            }
        } else {
            $this->logger->error("RSR0012 Can't find HiveKey object in CurrentControlSet.");
            return [];
        }

        $runningServices = [];

        if ($servicesHiveKey instanceof HiveKey) {
            $serviceKeys = $servicesHiveKey->getChildKeys();
            $this->logger->debug("RSR0010 Found " . count($serviceKeys) . " service keys.");
            foreach ($serviceKeys as $serviceName) {
                // Being Strict is Being Kind
                if ($servicesHiveKey[$serviceName] instanceof HiveKey) {
                    if (!isset($servicesHiveKey[$serviceName]['Type']) || !isset($servicesHiveKey[$serviceName]['Start'])) {
                        $this->logger->warning(
                            "RSR0014 Skipping service key entry because 'Type' or 'Start' are not defined",
                            [$servicesHiveKey[$serviceName]]
                        );
                        continue;
                    }
                    $type = Hive::decodeDword(strval($servicesHiveKey[$serviceName]['Type']));
                    $start = Hive::decodeDword(strval($servicesHiveKey[$serviceName]['Start']));
                    if (($type === self::WIN32_STAND_ALONE_SERVICE || $type === self::WIN32_SHARED_SERVICE) &&
                        ($start === ServiceStart::AUTO || $start === ServiceStart::DEMAND)) {
                        if (isset($servicesHiveKey[$serviceName]['DisplayName'])) {
                            $displayName = Hive::decodeRegSz(strval($servicesHiveKey[$serviceName]['DisplayName']));
                            if (empty($displayName) || preg_match('/^@/', $displayName)) {
                                $displayName = null;
                            }
                            $service = new WindowsService($displayName, $serviceName);
                            $runningServices[$service->getId()] = $service;
                        }
                    }
                } else {
                    $this->logger->warning(
                        "RSR0013 Skipping service key entry that appears to be a string and not HiveKey Object",
                        ['serviceName' => $serviceName, 'servicesHiveKey' => $servicesHiveKey]
                    );
                }
            }
        }

        return $runningServices;
    }

    /**
     * Returns the current control set registry key name.
     * For example: ControlSet001, ControlSet002, etc
     *
     * @param Hive $hive
     * @return string
     */
    private function getCurrentControlSet(Hive $hive): string
    {
        $hive->load('Select');

        if (isset($hive['Select']) && $hive['Select'] instanceof HiveKey) {
            if (isset($hive['Select']['Current'])) {
                // Get the current control set in _decimal_ and pad it out to 3 digits.
                // For example: 001, 002, 003 ... 999
                $controlSetNum = Hive::decodeDword(strval($hive['Select']['Current']));
                $controlSetNum = str_pad(strval($controlSetNum), 3, '0', STR_PAD_LEFT);
                unset($hive['Select']);
                return 'ControlSet' . $controlSetNum;
            } else {
                $this->logger->warning("RSR0015 Cannot Locate Select/Current Key in given Hive", [$hive]);
            }

            unset($hive['Select']);
        }
        $this->logger->warning("RSR0016 Returning empty control set [could not find in] given Hive", [$hive]);
        return '';
    }
}
