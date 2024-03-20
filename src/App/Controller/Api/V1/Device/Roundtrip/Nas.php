<?php

namespace Datto\App\Controller\Api\V1\Device\Roundtrip;

use Datto\Roundtrip\RoundtripManager;

/**
 * API for NAS roundtrip.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class Nas extends AbstractRoundtrip
{
    /* @var RoundtripManager */
    private $roundtripManager;

    public function __construct(RoundtripManager $roundtripManager)
    {
        $this->roundtripManager = $roundtripManager;
    }

    /**
     * Start a NAS roundtrip.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agents" = @Symfony\Component\Validator\Constraints\Type(type="array"),
     *   "shares" = @Symfony\Component\Validator\Constraints\Type(type="array"),
     *   "enclosures" = @Symfony\Component\Validator\Constraints\Type(type="array"),
     *   "confirmationEmail" = @Symfony\Component\Validator\Constraints\Type(type="string"),
     *   "encrypt" = @Symfony\Component\Validator\Constraints\Type(type="bool"),
     *   "inhibitBackups" = @Symfony\Component\Validator\Constraints\Type(type="bool"),
     *   "cloudSync" = @Symfony\Component\Validator\Constraints\Type(type="bool")
     * })
     *
     * @param array $agents Snapshots of agents to sync. See below for expected format
     * @param array $shares Snapshots of shares to sync. See below for expected format
     * @param string $nic The network interface the NAS exists on
     * @param string $nas NAS to create the roundtrip pool on
     * @param string $confirmationEmail Address to send emails to on completion
     * @param bool $inhibitBackups True to stop backups occurring during the roundtrip process
     * @return bool True if successful. An exception will occur on failure
     *
     * Expected array format:
     * "agents|shares" => [
     *     "<uuid>" => [],  // Value of the nested array is not used, but key name must be the uuid
     *     ...
     * ]
     */
    public function start(
        array $agents,
        array $shares,
        string $nic,
        string $nas,
        string $confirmationEmail,
        bool $inhibitBackups
    ): bool {
        $this->roundtripManager->startNas(
            $agents,
            $shares,
            $nic,
            $nas,
            $confirmationEmail,
            $inhibitBackups
        );

        return true; // All endpoints must return a value
    }

    /**
     * Get the status of a NAS Roundtrip.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     *
     * @return array NAS Roundtrip status data.
     */
    public function getStatus(): array
    {
        return $this->statusToArray($this->roundtripManager->getNasStatus());
    }

    /**
     * Cancel a running NAS roundtrip.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     *
     * @return bool True if successful. An exception will occur on failure
     */
    public function cancel(): bool
    {
        $this->roundtripManager->cancelNas();

        return true; // All endpoints must return a value
    }

    /**
     * Retrieve a list of local NAS targets.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "nic" = @Symfony\Component\Validator\Constraints\Type(type="string")
     * })
     *
     * @param string $nic Name of the network interface to check for targets
     * @return array List of available NAS targets
     */
    public function getTargets(string $nic): array
    {
        $nasTargets = $this->roundtripManager->getTargets($nic);

        $result = [];
        foreach ($nasTargets as $target) {
            $result[] = [
                'hostname' => $target->getHostname(),
                'address' => $target->getAddress(),
                'name' => $target->getName(),
                'protocolVersion' => $target->getProtocolVersion(),
                'size' => $target->getSize()
            ];
        }

        return $result;
    }

    /**
     * Retrieve a list of active network interfaces.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ROUNDTRIP")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ROUNDTRIP")
     *
     * @return array List of active NICs
     */
    public function getNics(): array
    {
        $networkInterfaces = $this->roundtripManager->getNics();

        $result = [];
        foreach ($networkInterfaces as $networkInterface) {
            $result[] = [
                'name' => $networkInterface->getName(),
                'address' => $networkInterface->getAddress(),
                'mac' => $networkInterface->getMac(),
                'nicToNic' => $networkInterface->isNicToNic(),
                'carrier' => $networkInterface->isCarrier()
            ];
        }

        return $result;
    }
}
