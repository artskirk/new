<?php

namespace Datto\Utility\Network;

use Datto\Common\Resource\ProcessFactory;
use Throwable;

/**
 * Utility to perform DNS Lookups
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class DnsLookup
{
    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Perform a DNS Lookup for the given Hostname, returning the IpAddress if found
     *
     * @param string $hostName The hostname to perform a DNS lookup of
     *
     * @return IpAddress|null The IP Addresses for the given hostname
     */
    public function lookup(string $hostName): ?IpAddress
    {
        try {
            $output = $this->processFactory
                ->get(['dig', '-4', '+search', '+short', $hostName])
                ->setTimeout(60)
                ->mustRun()
                ->getOutput();

            // Dig can return multiple results (e.g. dns.google resolves to 8.8.8.8 and 8.8.4.4).
            // For simplicity, just return the first
            $results = explode(PHP_EOL, trim($output));

            // Convert the dig output into an IP Address. If the output from dig wasn't an IP, this will return null.
            return IpAddress::fromAddr($results[0]);
        } catch (Throwable $throwable) {
            return null;
        }
    }

    /**
     * Perform a reverse DNS lookup, getting the hostname associated with a given IP address
     *
     * @param IpAddress $address The IP Address to lookup
     * @return string The hostname associated with the given IP, or an empty string if the lookup failed
     */
    public function reverseLookup(IpAddress $address): string
    {
        try {
            $output = $this->processFactory
                ->get(['dig', '+short', '-x', $address->getAddr()])
                ->setTimeout(60)
                ->mustRun()
                ->getOutput();

            return trim($output);
        } catch (Throwable $throwable) {
            return '';
        }
    }
}
