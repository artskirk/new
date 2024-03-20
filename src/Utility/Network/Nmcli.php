<?php

namespace Datto\Utility\Network;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Util\RetryHandler;
use Datto\Util\RetryAttemptsExhaustedException;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Wrapper for nmcli
 *
 * NOTE You probably want to use CachingNmcli in your code instead of directly using this class.
 * Nmcli operations are expensive and add up quickly when not cached.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class Nmcli implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const RETRY_SLEEP_SECONDS = 2;
    private const CONNECTION_SHOW_FIELDS = [
        'NAME',
        'UUID',
        'TYPE',
        'TIMESTAMP',
        'TIMESTAMP-REAL',
        'AUTOCONNECT',
        'AUTOCONNECT-PRIORITY',
        'READONLY',
        'DBUS-PATH',
        'ACTIVE',
        'DEVICE',
        'STATE',
        'ACTIVE-PATH',
        'SLAVE',
        'FILENAME'
    ];

    private ProcessFactory $processFactory;
    private RetryHandler $retryHandler;

    public function __construct(
        ProcessFactory $processFactory,
        RetryHandler $retryHandler
    ) {
        $this->processFactory = $processFactory;
        $this->retryHandler = $retryHandler;
    }

    /**
     * Performs a NetworkManager connectivity check, returning the current connectivity state. According to the
     * documentation, the possible states are as follows:
     *   none: the host is not connected to any network.
     *   portal: the host is behind a captive portal and cannot reach the full Internet.
     *   limited: the host is connected to a network, but it has no access to the Internet.
     *   full: the host is connected to a network and has full access to the Internet.
     *   unknown: the connectivity status cannot be found out.
     *
     * @param bool $check true to force a connectivity re-check, or false to use NetworkManager's cached connectivity
     * @return string The connectivity state (none, portal, limited, full, unknown)
     */
    public function networkingConnectivity(bool $check = false): string
    {
        $output = $this->processFactory
            ->get(array_merge(['nmcli', 'networking', 'connectivity'], $check ? ['check'] : []))
            ->mustRun()
            ->getOutput();

        return trim($output);
    }

    /**
     * @return string[] Returns the uuid for every connection on the system
     */
    public function getConnectionUuids(): array
    {
        return array_values(array_filter(array_map(static fn ($connection) => $connection['UUID'] ?? null, $this->connectionShow())));
    }

    /**
     * Fetch all the fields for every connection on the system
     *
     * @return array<int, array<string, string>> Details for every connection. First level of array is connections. Nested level is all fields for the connection as ['field' => 'value'] associative pairs.
     */
    public function connectionShowDetails(): array
    {
        $uuids = $this->getConnectionUuids();

        $output = $this->processFactory
            ->get(array_merge(['nmcli', '--escape', 'no', '--terse', '--fields', 'all,GENERAL,IP4', 'connection', 'show'], $uuids))
            ->mustRun()
            ->getOutput();

        $lines = explode("\n\n", trim($output));
        $linesParsed = array_map([$this, 'parseNewlineSeparatedFields'], $lines);
        $connections = array_values(array_filter($linesParsed));

        return $connections;
    }

    /**
     * Return metadata about all the connections on the system by querying NetworkManager for a handful of relevant
     * fields. This metadata can be used to filter connections. Valid fields are as follows:
     *   NAME, UUID, TYPE, TIMESTAMP, TIMESTAMP-REAL, AUTOCONNECT, AUTOCONNECT-PRIORITY, READONLY, DBUS-PATH,
     *   ACTIVE, DEVICE, STATE, ACTIVE-PATH, SLAVE, FILENAME
     *
     * @note Some of this information (e.g. FILENAME) is only available in this "high level" query of connections.
     * As best I can tell, for reasons I can't understand, it's not possible to get a connection filename directly
     * using `con show <id>`.
     *
     * @return array<int, array<string, string>> The above subset of fields for every connection. First level of array is connections. Nested level is fields for the connection as ['field' => 'value'] associative pairs.
     */
    public function connectionShow(): array
    {
        return $this->retryHandler->executeAllowRetry(
            function () {
                $output = $this->processFactory
                    ->get(['nmcli', '--terse', '--fields', implode(',', self::CONNECTION_SHOW_FIELDS), 'connection', 'show'])
                    ->mustRun()
                    ->getOutput();

                $lines = explode(PHP_EOL, trim($output));
                $linesParsed = array_filter(array_map([$this, 'parseConnectionShowFields'], $lines));
                $connections = array_values(array_filter($linesParsed));

                $this->checkDuplicateConnections($connections);

                return $connections;
            },
            RetryHandler::DEFAULT_RETRY_ATTEMPTS,
            self::RETRY_SLEEP_SECONDS,
            true,
            RetryAttemptsExhaustedException::DEFAULT_MAX_ATTEMPT_MESSAGES
        );
    }

    /**
     * Add a new connection to the system
     *
     * @param string $type The type of connection
     * @param string $iface The name of the interface that will be managed by this connection
     * @param string $name The name of the connection
     * @param string[] $extra Any extra parameters needed at creation time
     *
     * @return array{output: string, error: string} Stdout and stderr for the nmcli command used to add the connection
     */
    public function connectionAdd(string $type, string $iface, string $name, array $extra = []): array
    {
        $process = $this->processFactory
            ->get(array_merge(
                ['nmcli', 'connection', 'add', 'type', $type, 'ifname', $iface, 'con-name', $name],
                $extra
            ))
            ->mustRun();

        $output = trim($process->getOutput());
        $error = trim($process->getErrorOutput());

        return [
            'output' => $output,
            'error' => $error
        ];
    }

    /**
     * Remove a connection from the system
     *
     * @param string $identifier Uuid, interface name, connection path, or dbus path for the connection to delete
     */
    public function connectionDelete(string $identifier): void
    {
        $this->processFactory->get(['nmcli', 'connection', 'delete', $identifier])->mustRun();
    }

    /**
     * Modify the value of one or more fields in a single transaction.
     * @see https://networkmanager.dev/docs/api/latest/nm-settings-nmcli.html
     *
     * @param string $identifier Uuid, interface name, connection path, or dbus path for the connection to modify
     * @param string[] $fields Non-associative array containing fields and their values to set
     */
    public function connectionModify(string $identifier, array $fields): void
    {
        $this->processFactory->get(array_merge(['nmcli', 'connection', 'modify', $identifier], $fields))->mustRun();
    }

    /**
     * @param string $identifier Uuid, interface name, connection path, or dbus path for the connection to bring up
     * @param int $wait The number of seconds to wait for the connection to fully activate, or 0 to return without waiting
     */
    public function connectionUp(string $identifier, int $wait): void
    {
        $this->processFactory->get(['nmcli', '--wait', $wait, 'connection', 'up', $identifier])->mustRun();
    }

    /**
     * @param string $identifier Uuid, interface name, connection path, or dbus path for the connection to bring down
     */
    public function connectionDown(string $identifier): void
    {
        // This command will return a non-zero exit code if a connection is already down. Easier here just to
        // ignore that exit code when we set a connection down.
        $this->processFactory->get(['nmcli', 'connection', 'down', $identifier])->run();
    }

    /**
     * Tells NetworkManager to reload its connections from disk. Similar to a systemctl daemon-reload, this only makes
     * NetworkManager aware of changes, but does not cause the changes to be applied.
     */
    public function connectionReload(): void
    {
        $this->processFactory->get(['nmcli', 'connection', 'reload'])->mustRun();
    }

    /**
     * Enables networking. You should generally reload connections right before doing this, so that the network
     * comes up with the most up-to-date configuration.
     */
    public function networkingOn(): void
    {
        $this->processFactory->get(['nmcli', 'networking', 'on'])->mustRun();
    }

    /**
     * Disables all networking entirely. This will bring down and delete all network interfaces.
     */
    public function networkingOff(): void
    {
        $this->processFactory->get(['nmcli', 'networking', 'off'])->mustRun();
    }

    /**
     * @return string[] Returns the interface name for every device on the system
     */
    public function getDeviceNames(): array
    {
        return array_values(array_filter(array_map(static fn ($connection) => $connection['GENERAL.DEVICE'] ?? null, $this->deviceShowDetails())));
    }

    /**
     * Fetch all the fields for every device on the system
     *
     * @return array<int, array<string, string>> Details for every device. First level of array is devices. Nested level is string details for the device as ['field' => 'value'] associative pairs.
     */
    public function deviceShowDetails(): array
    {
        $output = $this->processFactory
            ->getFromShellCommandLine('nmcli --escape no --terse --fields all device show')
            ->mustRun()
            ->getOutput();

        $lines = explode("\n\n", trim($output));
        $linesParsed = array_map([$this, 'parseNewlineSeparatedFields'], $lines);
        $devices = array_values(array_filter($linesParsed));

        return $devices;
    }

    /**
     * Parses newline delimited fields from commands such as `nmcli -t --escape no con show eth0`.
     * Example output:
     *     connection.id:br0
     *     connection.uuid:7fd060e6-8afc-4f1f-a744-34bbd37d0417
     *     connection.stable-id:
     *     connection.type:bridge
     *     DHCP4.OPTION[1]:dhcp_lease_time = 120
     *     DHCP4.OPTION[2]:domain_name = lan
     *
     * @param string $rawLines Lines from nmcli output with a field and value on each line.
     * @return array<string, string> Associative array of field name to value. Array values are concatenated with &.
     */
    private function parseNewlineSeparatedFields(string $rawLines): array
    {
        $lines = array_filter(explode(PHP_EOL, $rawLines));

        foreach ($lines as $line) {
            // If the key is an array type, we'll need to parse that into a nested structure.
            // In the example $field strings below, IP4.ROUTE is the key for an array of two elements.
            //     IP4.ROUTE[1]:dst = 0.0.0.0/0, nh = 192.168.2.1, mt = 427
            //     IP4.ROUTE[2]:dst = 192.168.2.0/24, nh = 0.0.0.0, mt = 427
            if (preg_match('/^([^\[:]+)(\[\d+])?:(.+)$/', $line, $matches)) {
                $key = $matches[1];
                $isArray = !empty($matches[2]);
                $value = trim($matches[3]);

                // Array elements are concatenated together with & as a separator
                if ($isArray && isset($fields[$key])) {
                    $fields[$key] .= '&' . $value;
                } else {
                    $fields[$key] = $value;
                }
            }
        }

        return $fields ?? [];
    }

    /**
     * Parses colon delimited fields from commands such as `nmcli -t -f NAME,UUID,ACTIVE,DEVICE con show`.
     * Note that these fields are position based and will match the order that they're requested from nmcli.
     * Example output:
     *     eth1:b0216cec-ac2e-46d1-856e-9fd1edaa3645:yes:eth1
     *     br0.1:6766930b-43b1-4337-8db8-88fd92f199ac:no:
     *
     * @param string $line Line from nmcli output with fields separated by colons.
     * @param string[] $expectedFields Fields that should be parsed in the same order passed to nmcli.
     * @return array<string, string> Associative array of field name to value.
     */
    private function parseColonSeparatedFields(string $line, array $expectedFields): array
    {
        // It's rare, but some fields (e.g. NAME) can have colons, which will be escaped. Rather than
        // a simple `explode()` here, use a regex to split on colons, unless they're preceded by '\'
        $tokens = preg_split('/(?<!\\\):/', $line);
        if (count($tokens) !== count($expectedFields)) {
            throw new Exception("Error parsing nmcli output. line: '$line' tokens: " . json_encode($tokens));
        }

        // Create the info structure, un-escaping the strings if necessary
        foreach ($expectedFields as $i => $field) {
            $fields[$field] = str_replace(['\\\\', '\:'], ['\\', ':'], $tokens[$i]);
        }

        return $fields ?? [];
    }

    private function parseConnectionShowFields(string $connectionLine): array
    {
        return $this->parseColonSeparatedFields($connectionLine, self::CONNECTION_SHOW_FIELDS);
    }

    /**
     * Check if 'nmcli' command output returned duplicated connection UUIDs
     *
     * In some rare cases and on specific hardware (S5X and S4B3) the `nmcli` command  returns the same
     * connection twice for brief amount of time after connection has been modified. This method detects
     * this temporary state and throws so that it can be used from within the RetryHandler callback to
     * "wait out" such transient state before returning results to the caller. It's possible that this
     * issue will stop happening in the future e.g. due to kernel/networkmanager update, so each time
     * the condition is hit, a warning log is recorded so we can track it.
     **/
    private function checkDuplicateConnections(array $connections): void
    {
        $uuids = array_filter(array_map(static fn ($connection) => $connection['UUID'] ?? null, $connections));
        $duplicates = count(array_unique($uuids, SORT_STRING)) !== count($uuids);

        if ($duplicates) {
            $message = 'Duplicate connection UUID found in the list';
            $this->logger->warning('NMC0001 ' . $message);

            throw new Exception($message);
        }
    }
}
