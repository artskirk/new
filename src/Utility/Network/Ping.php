<?php

namespace Datto\Utility\Network;

use Datto\Common\Resource\ProcessFactory;

/**
 * Class Ping.
 * Provides network ping related functions.
 * @author Alex Joseph <ajoseph@datto.com>
 */
class Ping
{
    const FAILED_PING_PERCENTAGE = 100;
    const PING_THRESHOLD_PERCENTAGE = 65;
    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Pings the given server and determines percentage packet loss.
     * @param string $serverHost Server name to ping
     * @param int $pingSize Size of packet in bytes
     * @return int Percentage packet loss
     */
    public function pingServer(string $serverHost, int $pingSize): int
    {
        if ($serverHost) {
            $command = sprintf(
                'ping -w 3 -i .2 -c 10 -s %d %s ',
                $pingSize,
                escapeshellarg($serverHost)
            );
            $command .= "| grep 'packet loss' | awk '{print $6}' | sed 's/%//'";

            $commandOutput = $this->executeCommand($command);
            if (is_numeric($commandOutput[0])) {
                return (int)$commandOutput[0];
            }
        }

        return self::FAILED_PING_PERCENTAGE;
    }

    /**
     * @param string $command The command to execute
     * @return string[] The lines of process output
     */
    private function executeCommand(string $command): array
    {
        $process = $this->processFactory->getFromShellCommandLine($command);
        $process->mustRun();

        return explode(PHP_EOL, $process->getOutput());
    }
}
