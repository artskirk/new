<?php

namespace Datto\Asset\Agent;

use Datto\Networking\MacAddress\AgentCommandMacAddressService;
use Datto\Networking\MacAddress\ArpMacAddressService;

/**
 * This class is responsible for getting a unique id of the machine that an agent is paired with
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class MachineIdService
{
    private ArpMacAddressService $arpMacAddressService;
    private AgentCommandMacAddressService $agentCommandMacAddressService;

    public function __construct(
        ArpMacAddressService $arpMacAddressService,
        AgentCommandMacAddressService $agentCommandMacAddressService
    ) {
        $this->arpMacAddressService = $arpMacAddressService;
        $this->agentCommandMacAddressService = $agentCommandMacAddressService;
    }

    /**
     * Returns a unique machine id (mac address) for the machine that the agent is currently paired with
     */
    public function getMachineId(Agent $agent): string
    {
        try {
            return $this->arpMacAddressService->getMacAddress($agent->getName());
        } catch (\Exception $e1) {
        }

        try {
            return $this->agentCommandMacAddressService->getMacAddress($agent);
        } catch (\Exception $e2) {
        }

        throw new \Exception('Could not find the MAC address for the agent: ' . $agent->getKeyName());
    }
}
