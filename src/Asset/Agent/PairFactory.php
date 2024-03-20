<?php

namespace Datto\Asset\Agent;

use Datto\Agent\NewAgentHandler;
use Datto\Agent\PairAgentless;
use Datto\Agent\PairHandler;
use Datto\Config\DeviceConfig;
use Datto\Connection\ConnectionFactory;
use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Utility\Security\SecretString;

/**
 * Logic for creating PairHandlers
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class PairFactory
{
    /** @var DeviceConfig */
    private $deviceConfig;

    /**
     * @param DeviceConfig|null $deviceConfig
     */
    public function __construct(DeviceConfig $deviceConfig = null)
    {
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
    }

    /**
     * Create the appropriate PairHandler based on the passed parameters
     *
     * @param string $hostname
     * @param string|null $esxConnectionName
     * @param SecretString|null $encryptionPassphrase
     * @param string|null $offsiteTarget Device ID of the peer to peer offsite target or null for offsiting to datto cloud
     * @param bool $force if true and pairing a ShadowSnap agent, enable SMB minimum version 1 automatically.
     * @param bool $fullDisk if true and pairing agentless agent, force full disk backup
     * @return PairHandler
     */
    public function create(
        string $hostname,
        string $esxConnectionName = null,
        SecretString $encryptionPassphrase = null,
        string $offsiteTarget = null,
        bool $force = false,
        bool $fullDisk = false
    ): PairHandler {
        if ($this->isValidEsxConnection($esxConnectionName)) {
            return new PairAgentless($hostname, $esxConnectionName, $fullDisk, $encryptionPassphrase, $offsiteTarget);
        }

        return new NewAgentHandler($hostname, null, $encryptionPassphrase, $offsiteTarget, $force);
    }

    /**
     * Checks whether the esx connection can be used to pair an agent
     *
     * @param $esxConnectionName
     * @return bool
     */
    private function isValidEsxConnection($esxConnectionName): bool
    {
        if (!empty($esxConnectionName) && !$this->deviceConfig->isAltoXL()) {
            $conn = ConnectionFactory::create(
                $esxConnectionName,
                ConnectionType::LIBVIRT_ESX()
            );

            return $conn instanceof EsxConnection && $conn->isValid();
        }
        return false;
    }
}
