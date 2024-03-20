<?php

namespace Datto\Networking\MacAddress;

use Datto\Common\Resource\ProcessFactory;
use Datto\Security\CommonRegexPatterns;

/**
 * This class is a wrapper around the ARP binary and is responsible for returning the mac address of an agent.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ArpMacAddressService
{
    private const ARP_OUTPUT_LINE_REGEX = '/([^\s]+)(?:.*\()(\d+\.\d+\.\d+\.\d+)(?:\) at )(.{2}:.{2}:.{2}:.{2}:.{2}:.{2})/';

    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory = null)
    {
        $this->processFactory = $processFactory ?? new ProcessFactory();
    }

    /**
     * @param string $address An ip address, hostname, or fqdn. Examples: '10.0.20.231', 'datto-pc', or 'datto-pc.datto.lan'
     * @return string The mac address of the machine at $address. Colons and dashes are striped.
     */
    public function getMacAddress(string $address): string
    {
        $isIp = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $isHostname = preg_match(CommonRegexPatterns::HOSTNAME_RFC_1123, $address);
        $isFqdn = !$isIp && !$isHostname;

        $process = $this->processFactory->get(['arp', '-a']);
        $process->mustRun();

        if (preg_match_all(self::ARP_OUTPUT_LINE_REGEX, $process->getOutput(), $captureGroups, PREG_SET_ORDER)) {
            foreach ($captureGroups as $captureGroup) {
                $fullyQualifiedDomainName = strtolower($captureGroup[1]);
                $hostname = explode('.', $fullyQualifiedDomainName)[0];
                $ip = $captureGroup[2];
                $macAddress = strtolower(preg_replace('/[:-]/', '', $captureGroup[3]));

                if (($isIp && $ip === $address) ||
                    ($isHostname && strcasecmp($hostname, $address) === 0) ||
                    ($isFqdn && strcasecmp($fullyQualifiedDomainName, $address) === 0)
                ) {
                     // If the target was a hostname or fqdn, then it's possible for there to be multiple entries in the
                     // arp table. So to handle this case, we're just going to return the first mac address we see, as
                     // there is no way to know from hostname/fqdn alone which one is "correct".
                    return $macAddress;
                }
            }
        }

        throw new \Exception('Arp could not find the mac address for the agent: ' . $address);
    }
}
