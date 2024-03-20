<?php

namespace Datto\Asset\Agent;

use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\AssetType;
use Datto\Log\LoggerFactory;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Validates and runs commands on agent hsot systems.
 * Compatible with DWA and ShadowProtect agents only.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 * @author Andrew Cope <acope@datto.com>
 */
class RemoteCommandService
{
    /** Batch shell special characters in regex-exclude form */
    const BAD_ARG_CHARS_MATCH = '[^&#;`|~<>^\*\?\(\)\[\]\{\}\$\s]';
    const IP_ARGUMENT_MATCH = '\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}';

    const MANDATORY_ARGUMENT_MATCH = self::BAD_ARG_CHARS_MATCH . '+';
    const ANY_ARGUMENT_MATCH = self::BAD_ARG_CHARS_MATCH . '*';

    const ACCEPT_NO_ARGUMENT = '/^$/';
    const ACCEPT_MANDATORY_ARGUMENT = '/^' . self::MANDATORY_ARGUMENT_MATCH . '$/';
    const ACCEPT_ANY_ARGUMENT = '/^' . self::ANY_ARGUMENT_MATCH . '$/';

    const ALLOWED_COMMANDS = [
        'bcdedit'           => '/enum',
        'chkntfs'           => self::ACCEPT_MANDATORY_ARGUMENT,
        'driverquery'       => self::ACCEPT_ANY_ARGUMENT,
        'fltmc'             => [
            'filters',
            'volumes',
            'instances'
        ],
        'fsutil'            => "/^dirty\s+query\s+" . self::ANY_ARGUMENT_MATCH . "$/",
        'ipconfig'          => self::ACCEPT_ANY_ARGUMENT,
        'msinfo32'          => "/^\/report\s+" . self::MANDATORY_ARGUMENT_MATCH . "$/",
        'mountvol'          => self::ACCEPT_NO_ARGUMENT,
        'net'               => [
            '/^stats\s+srv$/',
            'start',
            "/^view\s+" . self::MANDATORY_ARGUMENT_MATCH . "$/"
        ],
        'netsh'             => '/^winhttp\s+show\s+proxy$/',
        'proxycfg.exe'      => self::ACCEPT_NO_ARGUMENT,
        'reg'               => [
            '/^delete\s+HKEY_LOCAL_MACHINE\\\\SYSTEM\\\\CurrentControlSet\\\\Enum\\\\STORAGE\\\\STC_Snapshot\s+\/f$/',
            '/^add\s+HKEY_LOCAL_MACHINE\\\\SOFTWARE\\\\AppAssure\\\\Replay\\\\Agent\\\\logging\s+\/f\s+\/v\s+EnableAutoUpload\s+\/t\s+REG_DWORD\s+\/d\s+00000000$/'
        ],
        'schtasks'          => self::ACCEPT_NO_ARGUMENT,
        'systeminfo'        => self::ACCEPT_NO_ARGUMENT,
        'tasklist'          => self::ACCEPT_NO_ARGUMENT,
        'ver'               => self::ACCEPT_NO_ARGUMENT,
        'vssadmin'          => "/^list\s+" . self::MANDATORY_ARGUMENT_MATCH . "$/",
        'wevtutil'          => [
            "/^export-log\s+Application\s+" . self::MANDATORY_ARGUMENT_MATCH . "$/",
            '/^qe Application \/q:\*\[Application\] \/f:text$/',
            '/^qe System \/q:\*\[System\] \/f:text$/',
        ],
        'wmic'              => [
            "/^" . self::MANDATORY_ARGUMENT_MATCH . "\s+list\s+" . self::MANDATORY_ARGUMENT_MATCH . "(\s+\/format:" . self::MANDATORY_ARGUMENT_MATCH . ")?$/",
            "/^" . self::MANDATORY_ARGUMENT_MATCH . "\s+list(\s+\/format:" . self::MANDATORY_ARGUMENT_MATCH . ")?$/",
            "/^list\s+" . self::MANDATORY_ARGUMENT_MATCH . "(\s+\/format:" . self::MANDATORY_ARGUMENT_MATCH . ")?$/",
            "/^" . self::MANDATORY_ARGUMENT_MATCH . "\s+get\s+" . self::MANDATORY_ARGUMENT_MATCH . "(\s+\/format:" . self::MANDATORY_ARGUMENT_MATCH . ")?$/",
            "/^" . self::MANDATORY_ARGUMENT_MATCH . "\s+get(\s+\/format:" . self::MANDATORY_ARGUMENT_MATCH . ")?$/",
            "/^get\s+" . self::MANDATORY_ARGUMENT_MATCH . "(\s+\/format:" . self::MANDATORY_ARGUMENT_MATCH . ")?$/"
        ],
        "/^\\\\\\\\" . self::IP_ARGUMENT_MATCH . "\\\\restartagent\\\\restarts2vm.exe$/" => self::ACCEPT_NO_ARGUMENT,
        "/^\\\\\\\\" . self::IP_ARGUMENT_MATCH . "\\\\FIXVSS\\\\FIXVSS64bit.bat$/"       => self::ACCEPT_NO_ARGUMENT,
        "/^\\\\\\\\" . self::IP_ARGUMENT_MATCH . "\\\\FIXVSS\\\\FIXVSS32bit.bat$/"       => self::ACCEPT_NO_ARGUMENT,
    ];

    const DEFAULT_WORKING_DIRECTORY = 'C:\\';

    /** @var AgentService */
    private $agentService;

    /** @var AgentApiFactory */
    private $agentApiFactory;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param AgentService|null $agentService
     * @param AgentApiFactory|null $agentApiFactory
     * @param DeviceLoggerInterface|null $logger
     */
    public function __construct(
        AgentService $agentService = null,
        AgentApiFactory $agentApiFactory = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->agentService = $agentService ?: new AgentService();
        $this->agentApiFactory = $agentApiFactory ?: new AgentApiFactory();
        $this->logger = $logger;
    }

    /**
     * Run a command on a Windows agent
     *
     * @param string $agentKeyName Agent to run the command on
     * @param string $command Command to run
     * @param array $arguments Arguments to pass to the command
     * @param string $directory Directory to run the command in
     * @return RemoteCommandResult Data object containing the response from the command
     */
    public function runCommand(
        string $agentKeyName,
        string $command,
        array $arguments = [],
        string $directory = self::DEFAULT_WORKING_DIRECTORY
    ): RemoteCommandResult {
        $this->validateCommand($command, $arguments);

        $logger = $this->logger ?: LoggerFactory::getAssetLogger($agentKeyName);

        $agent = $this->agentService->get($agentKeyName);
        if ($agent->isRescueAgent() || $agent->getType() !== AssetType::WINDOWS_AGENT) {
            throw new Exception("CMD0001 Agent $agentKeyName is not a Windows agent");
        }

        $argString = implode(' ', $arguments);
        $logger->info('CMD0002 Running command on agent', ['command' => $command, 'args' => $argString]);

        $agentApi = $this->agentApiFactory->createFromAgent($agent);
        $response = $agentApi->runCommand($command, $arguments, $directory);

        // Shadowsnap's output is split into stdout(0) and strerr(1), DWA puts it all in 'output'
        if (!empty($response['output']) && is_array($response['output'])) {
            $output = $response['output'][0];
        } else {
            $output = $response['output'] ?? '';
        }
        $exitCode = $response['errorlevel'] ?? -1; // Only present for shadowsnap
        $errorOutput = $response['output'][1] ?? ''; // Only present for shadowsnap

        return new RemoteCommandResult($response, $output, $errorOutput, $exitCode);
    }

    /**
     * Filters user input to a restrictive list of commands and arguments.
     *
     * @param string $hostCommand
     * @param array $hostArgs
     */
    private function validateCommand(string $hostCommand, array $hostArgs): void
    {
        // Need a single string to regex match against
        $hostArgString = implode(' ', $hostArgs);

        // Validate the command & grab argument list
        $found = false;
        $allowedCommandsList = null;
        foreach (self::ALLOWED_COMMANDS as $command => $args) {
            $isRegexMatch = $this->isRegexPattern($command) && preg_match($command, $hostCommand);
            $isExactMatch = $command === $hostCommand;
            if ($isRegexMatch || $isExactMatch) {
                $found = true;
                $allowedCommandsList = self::ALLOWED_COMMANDS[$command];
                break;
            }
        }
        if (!$found) {
            throw new Exception("CMD0003 Invalid command $hostCommand");
        }
        if (!is_array($allowedCommandsList)) {
            $allowedCommandsList = [$allowedCommandsList];
        }

        // Validate the arguments
        $validArgs = false;
        foreach ($allowedCommandsList as $args) {
            $isRegexMatch = $this->isRegexPattern($args) && preg_match($args, $hostArgString);
            $isExactMatch = $args === $hostArgString;
            if ($isRegexMatch || $isExactMatch) {
                $validArgs = true;
                break;
            }
        }
        if (!$validArgs) {
            throw new Exception("CMD0004 Invalid args '$hostArgString' for command $hostCommand");
        }
    }

    /**
     * Returns true if the string is a regular expression pattern
     *
     * @param string $string
     * @return bool
     */
    private function isRegexPattern(string $string): bool
    {
        return strpos($string, '/^') === 0 && substr($string, -2) === '$/';
    }
}
