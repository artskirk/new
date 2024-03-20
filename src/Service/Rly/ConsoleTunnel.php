<?php

namespace Datto\Service\Rly;

use Datto\Connection\Libvirt\EsxConnection;
use Datto\RemoteWeb\RemoteWebService;
use Datto\Restore\Virtualization\ConsoleType;
use Datto\Rly\Client;
use Datto\Virtualization\VmwareApiClient;
use Datto\Virtualization\VirtualMachine;
use Exception;
use RuntimeException;

/**
 * Handles setting up and tearing down RLY tunnels for VM remote consoles, no matter their type
 */
class ConsoleTunnel
{
    private const CONSOLE_CONNECTION_TAG_FORMAT = "%s-remote-console";

    /** @var Client */
    private $rlyClient;

    /** @var VmwareApiClient */
    private $vmwareApiClient;

    public function __construct(
        Client $rlyClient,
        VmwareApiClient $vmwareApiClient
    ) {
        $this->rlyClient = $rlyClient;
        $this->vmwareApiClient = $vmwareApiClient;
    }

    /**
     * Opens a rly tunnel to the remote console port of a locally accessible VM
     *
     * @param VirtualMachine $vm The VM that we want to open a console to
     * @param bool $restrictIpToHost Set to true to restrict the IP range allowed to access the rly connection
     */
    public function openRemoteConnection(VirtualMachine $vm, bool $restrictIpToHost): void
    {
        if (!RemoteWebService::isRlyRequest()) {
            // Opening a tunnel isn't necessary if we're not coming in through rly, so just return
            return;
        }

        $tag = sprintf(self::CONSOLE_CONNECTION_TAG_FORMAT, $vm->getName());
        $connection = $this->rlyClient->getConnectionByTag($tag);

        if (empty($connection)) {
            $info = $vm->getRemoteConsoleInfo();
            if ($info === null) {
                throw new RuntimeException("Agent doesn't support a remote tunnel");
            }

            $remoteEndpoint = $info->getHost() . ":" . $info->getPort();

            $ipRange = [];
            if ($restrictIpToHost) {
                $ipRange[] = RemoteWebService::getRemoteIp();
            }

            $tags[] = $tag;

            $rlyConnection = $this->rlyClient->open($remoteEndpoint, [], true, $ipRange, $tags);
            if (!isset($rlyConnection['id'])) {
                throw new Exception('Rly open did not return valid connection.');
            }

            $this->rlyClient->waitForConnectionStatus($tag, true);
        }
    }

    /**
     * Closes the rly tunnel to the remote console port of the given VM
     *
     * @param VirtualMachine $vm The VM that we want to close the rly tunnel to
     */
    public function closeRemoteConnection(VirtualMachine $vm): void
    {
        $tag = sprintf(self::CONSOLE_CONNECTION_TAG_FORMAT, $vm->getName());
        $connection = $this->rlyClient->getConnectionByTag($tag);
        if (!isset($connection['id'])) {
            throw new Exception('Unable to find remote console tunnel rly connection id for ' . $vm->getName());
        }

        $this->rlyClient->close($connection['id']);

        $this->rlyClient->waitForConnectionStatus($tag, false);
    }

    /**
     * @param string $vmName The vm to get information for
     * @return string[] An array containing values for consoleHost and consolePort, or null
     */
    public function getConnectionInfo(string $vmName): array
    {
        $tag = sprintf(self::CONSOLE_CONNECTION_TAG_FORMAT, $vmName);
        $connection = $this->rlyClient->getConnectionByTag($tag);

        $consoleHost = $connection['relayHost'] ?? null;
        $consolePort = $connection['relayPort'] ?? null;

        return [
            'consoleHost' => $consoleHost,
            'consolePort' => $consolePort
        ];
    }
}
