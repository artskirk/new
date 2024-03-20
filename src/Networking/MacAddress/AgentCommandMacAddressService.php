<?php

namespace Datto\Networking\MacAddress;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\RemoteCommandService;

/**
 * MacAddressService that uses AgentCommand to retrieve the MAC address.
 * Only works for Windows agents.
 *
 * @author John Roland <jroland@datto.com>
 */
class AgentCommandMacAddressService
{
    /** The line in ipconfig's output that contains the MAC address */
    const MAC_ADDRESS_FIELD = 'Physical Address';

    /** @var RemoteCommandService */
    private $remoteCommandService;

    public function __construct(RemoteCommandService $remoteCommandService = null)
    {
        $this->remoteCommandService = $remoteCommandService ?: new RemoteCommandService();
    }

    public function getMacAddress(Agent $agent)
    {
        $output = $this->remoteCommandService->runCommand(
            $agent->getKeyName(),
            'ipconfig',
            ['/all']
        )->getOutput();

        $macAddress = $this->getMacFromOutput($output);

        if ($macAddress === null) {
            throw new \Exception('Failed to get the MAC address of ' . $agent->getKeyName() . ' via AgentCommand.');
        }

        return $macAddress;
    }

    /**
     * Parse the ipconfig output to get the MAC address.
     * Example ipconfig output (we want the Physical Address):
     *
     * Windows IP Configuration
     *
     *    Host Name . . . . . . . . . . . . : QATB10-W2003r2c
     *    Primary Dns Suffix  . . . . . . . :
     *    Node Type . . . . . . . . . . . . : Unknown
     *    IP Routing Enabled. . . . . . . . : No
     *    WINS Proxy Enabled. . . . . . . . : No
     *    DNS Suffix Search List. . . . . . : datto.lan
     *
     * Ethernet adapter Local Area Connection:
     *
     *    Connection-specific DNS Suffix  . : datto.lan
     *    Description . . . . . . . . . . . : Intel(R) PRO/1000 MT Network Connection
     *    Physical Address. . . . . . . . . : 00-50-56-9A-04-86
     *    DHCP Enabled. . . . . . . . . . . : Yes
     *    Autoconfiguration Enabled . . . . : Yes
     *    IP Address. . . . . . . . . . . . : 10.0.120.170
     *    Subnet Mask . . . . . . . . . . . : 255.255.248.0
     *    Default Gateway . . . . . . . . . : 10.0.121.250
     *    DHCP Server . . . . . . . . . . . : 10.0.40.3
     *    DNS Servers . . . . . . . . . . . : 10.0.40.3
     *                                        10.0.40.4
     *    Lease Obtained. . . . . . . . . . : Sunday, December 04, 2016 10:44:59 PM
     *    Lease Expires . . . . . . . . . . : Friday, December 09, 2016 10:44:59 PM
     *
     *
     * @param string $ipconfigOutput output from the ipconfig /all command
     * @return string the mac address
     */
    private function getMacFromOutput(string $ipconfigOutput)
    {
        $macAddress = null;

        // Break the output up by lines
        $lines = explode("\n", $ipconfigOutput);

        foreach ($lines as $line) {
            // We want the line that starts with 'Physical Address'
            if (stripos(trim($line), static::MAC_ADDRESS_FIELD) === 0) {
                // Split by colon
                $elements = explode(':', $line);
                // get the last element then trim the whitespace
                $macAddress = end($elements);
                $macAddress = trim($macAddress);
                // remove all hyphens then convert all characters to lower case
                $macAddress = str_replace('-', '', $macAddress);
                $macAddress = strtolower($macAddress);
                break;
            }
        }

        return $macAddress;
    }
}
