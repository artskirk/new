<?php

namespace Datto\Iscsi;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Log\LoggerFactory;
use Datto\Common\Resource\Sleep;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Class for managing Windows iSCSI initiators via the iscsicli command-line utility.
 *
 * @author Dan Fuhry <dfuhry@datto.com>
 * @author Dakota Baber <dbaber@datto.com>
 * @author Dawid Zamirski <dzamirkski@datto.com>
 * @author Andrew Cope <acope@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class RemoteInitiator
{
    /**
     * Command line executable format to run multiple commands at once
     * and return a value on success or failure
     */
    const CMD_EXECUTABLE = 'cmd.exe';
    const CMD_EXECUTABLE_COMMAND = '/c';
    const CMD_EXECUTABLE_FORMAT = '%s %s > nul 2>&1 && echo %s || echo %s';

    const COMMAND_SUCCESS = 'COMMAND_SUCCESS';
    const COMMAND_FAILURE = 'COMMAND_FAILURE';

    const ISCSI_CLI_EXECUTABLE = 'iscsicli.exe';

    const ADD_TARGET_PORTAL_CMD = 'AddTargetPortal';
    const LOGIN_TARGET_CMD = 'QLoginTarget';
    const LOGOUT_TARGET_CMD = 'LogoutTarget';
    const SESSION_LIST_CMD = 'SessionList';

    const AUTOMOUNT_ENABLE_COMMANDS = [
        "echo automount enable noerr >> %TEMP%\\diskpart.txt",
        "diskpart /s %TEMP%\\diskpart.txt",
        "del %TEMP%\\diskpart.txt"
    ];

    const AUTOMOUNT_DISABLE_COMMANDS = [
        "echo automount disable noerr >> %TEMP%\\diskpart.txt",
        "diskpart /s %TEMP%\\diskpart.txt",
        "del %TEMP%\\diskpart.txt"
    ];

    const ISCSI_DEFAULT_PORT = 3260;

    /**
     * Boolean to track whether the portal is registered or not.
     *
     * @var bool
     * @todo fetch this in realtime from the agent
     */
    private $portalIsRegistered = false;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Sleep */
    private $sleep;

    /** @var AgentApi */
    private $agentApi;

    /**
     * @param string $agentKeyName
     * @param DeviceLoggerInterface|null $logger
     * @param Sleep|null $sleep
     * @param AgentApiFactory|null $agentApiFactory
     * @param AgentService|null $agentService
     */
    public function __construct(
        string $agentKeyName,
        DeviceLoggerInterface $logger = null,
        Sleep $sleep = null,
        AgentApiFactory $agentApiFactory = null,
        AgentService $agentService = null
    ) {
        $this->logger = $logger ?: LoggerFactory::getAssetLogger($agentKeyName);
        $agentService = $agentService ?: new AgentService();
        $agentApiFactory = $agentApiFactory ?: new AgentApiFactory();
        $this->agentApi = $agentApiFactory->createFromAgent($agentService->get($agentKeyName));
        $this->sleep = $sleep ?: new Sleep();
    }

    /**
     * Returns the list of sessions that have zero connections being used.
     *
     * An iSCSI initiator session that is in use can have 1 or more connections.  When a session exists on the
     * initiator, but it has 0 associated connections, we consider it to be "unused" (not currently in use).
     *
     * @return array[] An array of associative arrays, each of which contains the following keys: SessionId, TargetName
     */
    public function listUnusedSessions(): array
    {
        $unusedSessions = [];

        try {
            $output = $this->runCommand(self::ISCSI_CLI_EXECUTABLE, [self::SESSION_LIST_CMD]);
        } catch (Throwable $e) {
            // The command failed, so return the current (empty) array
            return $unusedSessions;
        }

        if (strpos($output, ' sessions') === false) {
            return $unusedSessions;
        }

        $output = explode(PHP_EOL, $output);

        foreach ($output as $line) {
            $line = trim($line);
            $key = trim(substr($line, 0, strpos($line, ':')));

            if ($key === 'Target Name') {
                $targetName = substr($line, strpos($line, ':') + 2);
            } elseif ($key === 'Session Id') {
                $sessionId = substr($line, strpos($line, ':') + 2);
            } elseif ($key === 'Number Connections') {
                $numConnections = substr($line, strpos($line, ':') + 2);
                if ($numConnections === '0'
                    && !empty($sessionId)
                    && !empty($targetName)
                    && strpos($targetName, 'iqn.2007-01.net.datto') !== false) {
                    $unusedSessions[] = [
                        'SessionId' => $sessionId,
                        'TargetName' => $targetName
                    ];
                    $sessionId = '';
                    $targetName = '';
                }
            }
        }

        return $unusedSessions;
    }

    /**
     * List attached iSCSI devices.
     *
     * @return InitiatorDevice[]
     */
    public function listDevices(): array
    {
        $devices = [];

        try {
            $output = $this->runCommand(self::ISCSI_CLI_EXECUTABLE, [self::SESSION_LIST_CMD]);
        } catch (Throwable $e) {
            // The command failed, so return the current (empty) array
            return $devices;
        }

        if (strpos($output, 'Devices:') === false) {
            return $devices;
        }

        $output = explode(PHP_EOL, $output);

        // vpn = volume path names key (formatted differently than the others)
        // sid = session ID - this comes before the device list (it's organized
        //      by session)
        $found_devices = $vpn = $sid = false;
        $current_device = [];

        foreach ($output as $line) {
            $line = trim($line);
            $key = trim(substr($line, 0, strpos($line, ':')));

            if ($found_devices) {
                if (empty($key)) {
                    continue;
                }

                if ($key === 'Session Id') {
                    $found_devices = false;
                    $sid = substr($line, strpos($line, ':') + 2);
                    continue;
                }

                if ($key === 'Device Type') {
                    if (count($current_device) > 1) {
                        $devices[] = new InitiatorDevice(
                            $current_device['Target Name'],
                            $current_device['Legacy Device Name'],
                            $current_device['Session Id']
                        );
                    }

                    $current_device = [
                        'Session Id' => $sid
                    ];
                    $vpn = false;
                } else {
                    if ($key === 'Volume Path Names') {
                        $vpn = true;
                        $current_device[$key] = [];
                    } else {
                        if ($vpn) {
                            $current_device['Volume Path Names'][] = $line;
                        }
                    }
                }

                if (!$vpn) {
                    $current_device[$key] = substr($line, strpos($line, ':') + 2);
                }
            } else {
                if ($line === 'Devices:') {
                    $found_devices = true;
                } else {
                    if ($key === 'Session Id') {
                        $sid = substr($line, strpos($line, ':') + 2);
                    }
                }
            }
        }

        if (count($current_device) > 1) {
            $devices[] = new InitiatorDevice(
                $current_device['Target Name'],
                $current_device['Legacy Device Name'],
                $current_device['Session Id']
            );
        }

        return $devices;
    }

    /**
     * Register this box's portal with the agent.
     *
     * @param string $portalIp
     * @param int $port Port will default to self::ISCSI_DEFAULT_PORT if omitted
     */
    public function registerPortal(string $portalIp, int $port = self::ISCSI_DEFAULT_PORT)
    {
        // String value arguments are required for the legacy interface
        $this->runCommand(
            self::ISCSI_CLI_EXECUTABLE,
            [
                self::ADD_TARGET_PORTAL_CMD,
                $portalIp,
                (string) $port
            ]
        );
        $this->portalIsRegistered = true;
    }

    /**
     * Log into a target. Doesn't necessarily need to be on this box.
     *
     * @param string $targetName Target name
     * @param string $user
     * @param string $password
     */
    public function loginToTarget(string $targetName, string $user = null, string $password = null)
    {
        $this->logger->info('RIN0001 Using iscsicli.exe to login...');
        $this->disableAutomount();
        $params = [self::LOGIN_TARGET_CMD, $targetName];
        if ($user && $password) {
            $params[] = $user;
            $params[] = $password;
        }

        $this->runIscsiCliCommand($params);
        $this->enableAutomount();
    }

    /**
     * Finds the volume name for a given target.
     *
     * @param string $targetName The target to discover the volume for
     * @return bool|string Returns false if the target is not found, otherwise the volume name
     */
    public function discoverVolume(string $targetName)
    {
        $this->disableAutomount();

        // I hate inserting random sleep() calls, but this seemed to resolve the issue where
        // the drive wasn't showing up in listDevices() after we had just attached it.
        //
        // Fuck you, Microsoft, and fuck your unreliable OS that we're all stuck trying to back up.
        $this->sleep->sleep(1);

        // online the disk
        foreach ($this->listDevices() as $device) {
            if ($device->getTargetName() === $targetName) {
                if (preg_match(
                    '#^\\\\\\\\.\\\\PhysicalDrive(\d+)$#',
                    $device->getLegacyName(),
                    $match
                )) {
                    $driveNumber = (int) $match[1];
                    $this->onlineDisk($driveNumber);
                }
                break;
            }
        }
        $this->sleep->sleep(1);

        $this->enableAutomount();

        if (!isset($driveNumber)) {
            return false;
        }

        return "\\\\?\\GLOBALROOT\\Device\\Harddisk{$driveNumber}\\Partition1";
    }

    /**
     * Log out from a target.
     *
     * @param string $targetName Target name
     * @return bool
     */
    public function logoutFromTarget(string $targetName): bool
    {
        $devices = $this->listDevices();
        foreach ($devices as $device) {
            if ($device->getTargetName() !== $targetName) {
                continue;
            }
            // String value arguments are required for the legacy interface
            return $this->logoutFromSession($device->getSessionId());
        }
        return true;
    }

    /**
     * Given a sessionId, logout from the target
     *
     * @param string $sessionId
     * @return bool
     */
    public function logoutFromSession(string $sessionId): bool
    {
        $this->runCommand(
            self::ISCSI_CLI_EXECUTABLE,
            [
                self::LOGOUT_TARGET_CMD,
                $sessionId
            ]
        );

        return true;
    }

    /**
     * Is the portal registered?
     *
     * @return bool
     */
    public function isPortalRegistered(): bool
    {
        return $this->portalIsRegistered;
    }

    /**
     * Online a disk.
     *
     * @param int $diskNumber Disk number
     */
    public function onlineDisk(int $diskNumber)
    {
        $commands = [
            sprintf("echo select disk %d > %%TEMP%%\\diskpart.txt", $diskNumber),
            "echo attribute disk clear readonly noerr >> %TEMP%\\diskpart.txt",
            "echo online disk noerr >> %TEMP%\\diskpart.txt",
            "mountvol %TEMP%\\Harddisk{$diskNumber}Partition1 /D",
            "md %TEMP%\\Harddisk{$diskNumber}Partition1",
            "diskpart /s %TEMP%\\diskpart.txt",
            sprintf("echo select disk %d > %%TEMP%%\\diskpart.txt", $diskNumber),
            "echo select partition 1 >> %TEMP%\\diskpart.txt",
            "echo assign mount=%TEMP%\\Harddisk{$diskNumber}Partition1 >> %TEMP%\\diskpart.txt",
            "diskpart /s %TEMP%\\diskpart.txt",
            "del %TEMP%\\diskpart.txt"
        ];
        $this->runMultipleCommands($commands);
    }

    /**
     * Offline a disk.
     *
     * @param int $diskNumber Disk number
     */
    public function offlineDisk(int $diskNumber)
    {
        $commands = [
            sprintf("echo select disk %d > %%TEMP%%\\diskpart.txt", $diskNumber),
            "echo select partition 1 >> %TEMP%\\diskpart.txt",
            "echo remove all dismount noerr >> %TEMP%\\diskpart.txt",
            sprintf("echo select disk %d >> %%TEMP%%\\diskpart.txt", $diskNumber),
            "echo offline disk >> %TEMP%\\diskpart.txt",
            "diskpart /s %TEMP%\\diskpart.txt",
            "rmdir /S /Q %TEMP%\\Harddisk{$diskNumber}Partition1",
            "del %TEMP%\\diskpart.txt"
        ];
        $this->runMultipleCommands($commands);
    }

    /**
     * Enable windows automounting
     */
    private function enableAutomount()
    {
        $this->runMultipleCommands(self::AUTOMOUNT_ENABLE_COMMANDS);
    }

    /**
     * Disable windows automounting
     */
    private function disableAutomount()
    {
        $this->runMultipleCommands(self::AUTOMOUNT_DISABLE_COMMANDS);
    }

    /**
     * Runs iscsicli.exe throwing an exception when any error happens
     * This executes similarly to runMultipleCommands, but we require
     * an explicit success or failure message.
     *
     * @param array $arguments
     */
    private function runIscsiCliCommand(array $arguments)
    {
        $command = sprintf(
            self::CMD_EXECUTABLE_FORMAT,
            static::ISCSI_CLI_EXECUTABLE,
            implode(' ', $arguments),
            static::COMMAND_SUCCESS,
            static::COMMAND_FAILURE
        );

        $output = $this->runCommand(self::CMD_EXECUTABLE, [self::CMD_EXECUTABLE_COMMAND, $command]);

        if (strpos($output, static::COMMAND_SUCCESS) !== false) {
            $this->logger->debug('RIN0002 iscsicli.exe command executed successfully');
        } else {
            throw new InitiatorException('iscsicli.exe command did not return successful');
        }
    }

    /**
     * Run multiple commands via cmd.exe /c
     *
     * @param array $commands
     * @return string
     */
    private function runMultipleCommands(array $commands = []): string
    {
        $commandString = implode(' & ', $commands);
        return $this->runCommand(self::CMD_EXECUTABLE, [self::CMD_EXECUTABLE_COMMAND, $commandString]);
    }

    /**
     * Executes a command on the agent system.
     *
     * @param string $command
     * @param array $arguments
     * @return string
     */
    private function runCommand(string $command, array $arguments): string
    {
        $response = $this->agentApi->runCommand($command, $arguments);
        if (!is_array($response)) {
            throw new InitiatorException("There was an error executing the command. Response: $response");
        }
        return trim($response['output'][0]);
    }
}
