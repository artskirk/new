<?php

namespace Datto\OwnCloud;

use Datto\Cloud\JsonRpcClient;
use Datto\Config\LocalConfig;
use Datto\Device\Serial;
use Datto\Log\LoggerAwareTrait;
use Datto\Rly\Client as RlyClient;
use Psr\Log\LoggerAwareInterface;

/**
 * External Network and Rly control for OwnCloud
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class OwnCloudExternal implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const LOCAL_CONFIG_EXTERNAL = "ownCloudExternal";
    private const LISTEN_PORT = 81;

    private LocalConfig $localConfig;
    private RlyClient $rlyClient;
    private JsonRpcClient $client;
    private Serial $serial;

    public function __construct(
        LocalConfig $localConfig,
        RlyClient $rlyClient,
        JsonRpcClient $client,
        Serial $serial
    ) {
        $this->localConfig = $localConfig;
        $this->rlyClient = $rlyClient;
        $this->client = $client;
        $this->serial = $serial;
    }

    /**
     * Returns whether or not the ownCloud external feature is enabled.
     *
     * @return bool True if ownCloud is available from external networks via rly, false otherwise
     */
    public function isEnabled(): bool
    {
        return $this->localConfig->has(self::LOCAL_CONFIG_EXTERNAL);
    }

    /**
     * Clears the external flag and stops the external runner to disable owncloud
     * availability outside the partner/customer network.
     */
    public function disable(): void
    {
        $this->logger->info('OCE0002 Disabling ownCloud External Service');
        $this->localConfig->clear(self::LOCAL_CONFIG_EXTERNAL);
        $this->stop();
    }

    /**
     * Stop the rly connection that makes ownCloud available outside the partner's network
     */
    public function stop(): void
    {
        if ($this->isExternalRlyConnectionUp()) {
            $this->logger->info('OCE0004 Stopping external connection');
            $connectionId = $this->getExternalRlyConnectionId();
            $this->rlyClient->close($connectionId);
        }
    }

    /**
     * Check if the rly connection for external owncloud access exists.
     * If the connection exists, rly will handle making sure that the ssh tunnel connecting the external
     * <mac>.dattoconnect.com domain to the device stays up.
     *
     * @return bool True if the rly connection exists
     */
    private function isExternalRlyConnectionUp(): bool
    {
        return $this->getExternalRlyConnectionId() !== '';
    }

    /**
     * Get the rly connection id for the tunnel that is handling external owncloud access.
     *
     * @return string The connection id if the tunnel is active or '' if the connection doesn't exist
     */
    private function getExternalRlyConnectionId(): string
    {
        $connections = $this->rlyClient->list();
        foreach ($connections as $connectionId => $connection) {
            if ($connection['sourceForwardPort'] !== self::LISTEN_PORT) {
                continue;
            }
            foreach ($connection['sourceDnsAliases'] ?? [] as $dnsAlias) {
                // match production or testing owncloud domain
                if (preg_match('/\w+\.dattoconnect(-test)?\.com$/', $dnsAlias)) {
                    return $connectionId;
                }
            }
        }
        return '';
    }
}
