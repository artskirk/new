<?php

namespace Datto\Rly;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Resource\Sleep;
use Datto\Log\LoggerFactory;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Runs rly client commands
 *
 * @author Matt Cheman <mcheman@datto.com>
 */
class Client
{
    /** @var ProcessFactory */
    private $processFactory;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Sleep */
    private $sleep;

    public function __construct(
        ProcessFactory $processFactory = null,
        DeviceLoggerInterface $logger = null,
        Sleep $sleep = null
    ) {
        $this->processFactory = $processFactory ?? new ProcessFactory();
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
        $this->sleep = $sleep ?? new Sleep();
    }

    /**
     * Open a rly connection
     *
     * @param string $definition Connection definition, i.e. <user>@<target> or [<forward-host>:]<forward-port>
     * @param string[] $dnsAliases Set up DNS alias for this connection. Variables '${id}' and '${source}' are expanded.
     * @param bool $gatewayPorts Expose the relay port to the public internet
     * @param string[] $restrictToIpRanges Restrict access to only allow the given ranges of IPs
     * @param string[] $tags The list of tags to add to this connection
     * @return array The opened connection details. Has the same format as the connections returned from rlyList()
     */
    public function open(
        string $definition,
        array $dnsAliases = [],
        $gatewayPorts = true,
        array $restrictToIpRanges = [],
        array $tags = []
    ): array {
        $commandLine = [
            'rly',
            'open',
            $definition,
            '--format',
            'json',
            $gatewayPorts ? '--gateway' : '--no-gateway'
        ];

        if (!empty($restrictToIpRanges)) {
            $commandLine[] = '--allowed-ip-ranges=' . implode(',', $restrictToIpRanges);
        }

        if (!empty($tags)) {
            $commandLine[] = '--tags=' . implode(',', $tags);
        }

        foreach ($dnsAliases as $alias) {
            $commandLine[] = '--dns-alias';
            $commandLine[] = $alias;
        }

        $process = $this->processFactory
            ->get($commandLine);
        $process->run();

        $output = $process->getOutput();
        $outputArray = json_decode($output, true);

        $connectionPresent = isset($outputArray['connections']) && count($outputArray['connections']) === 1;

        if (!$connectionPresent || !$process->isSuccessful()) {
            $this->logger->error("RLC0001 'open' did not return a connection.", ['output' => $output]);
            throw new Exception('Could not open connection');
        }

        return array_values($outputArray['connections'])[0];
    }

    /**
     * Close a rly connection
     *
     * @param string $connectionId The id of the connection to close. ex: 'sh79ee1pz5znr15d'
     */
    public function close(string $connectionId)
    {
        $this->processFactory
            ->get(['rly', 'close', $connectionId])->mustRun();
    }

    /**
     * List active rly connections on the device
     *
     * @return array of connections
     */
    public function list(): array
    {
        $process = $this->processFactory
            ->get(['rly', 'list', '--format', 'json']);

        $process->run();
        $output = $process->getOutput();
        $outputArray = json_decode($output, true);

        if (!isset($outputArray['connections']) || !$process->isSuccessful()) {
            $this->logger->error("RLC0002 'list' did not return any connections.", ['output' => $output]);
            throw new Exception('Could not list rly connections');
        }

        return $outputArray['connections'];
    }

    /**
     * Returns the first connection in the list that is tunneled to the given port
     *
     * @param string $tag
     * @return array The fields in the connection row returned from the list command
     */
    public function getConnectionByTag(string $tag)
    {
        $connections = $this->list();
        foreach ($connections as $connection) {
            if (!isset($connection['tags'])) {
                continue;
            } else {
                if (array_key_exists($tag, array_flip($connection['tags']))) {
                    return $connection;
                }
            }
        }

        return [];
    }

    /**
     * Wait for the relay connection identified by the given tag to be available for use
     *
     * @param string $tag The tag used to identify the connection that we're waiting for
     * @param bool $connectionOpen True to wait for a connection to open, false to wait for a connection to close
     * @param int $timeoutSeconds The maximum amount of time to wait for the tunnel to open
     */
    public function waitForConnectionStatus(string $tag, bool $connectionOpen, int $timeoutSeconds = 30)
    {
        do {
            $foundCorrectStatus = !empty($this->getConnectionByTag($tag)) === $connectionOpen;
            if (!$foundCorrectStatus) {
                $this->sleep->sleep(1);
                $timeoutSeconds--;
            }
        } while (!$foundCorrectStatus && $timeoutSeconds > 0);

        if (!$foundCorrectStatus) {
            throw new Exception("Timed out waiting for Rly connection to change status for tag $tag");
        }
    }
}
