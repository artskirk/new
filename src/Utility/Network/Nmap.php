<?php

namespace Datto\Utility\Network;

use Datto\Common\Resource\ProcessFactory;
use Throwable;

/**
 * Primary entry point for interacting with the `nmap` utility, providing convenience wrappers for basic host and port
 * scanning operations.
 */
class Nmap
{
    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Scan a host to check if it is up and responding.
     *
     * @param string $host The host to scan
     * @return bool True if the host is responsive
     */
    public function pingScan(string $host): bool
    {
        // NMAP Argument Docs:
        //  -sn: Disable port scanning, and perform a ping scan of the remote host only
        //  -PR: put nmap in control of ARP, allowing it to bypass ICMP for local subnet hosts, resulting in
        //       significantly faster and more reliable scans.
        $output = $this->processFactory->get(['nmap', '-sn', '-PR', $host])->mustRun()->getOutput();
        return (strpos($output, 'Host is up') !== false);
    }

    /**
     * Scans a host to see if a given TCP port is open
     *
     * @param string $host The hostname or IP of the host to scan
     * @param int $port The TCP Port number to check
     *
     * @return bool True if the port is open
     */
    public function tcpPortScan(string $host, int $port): bool
    {
        // NMAP Argument Docs:
        //  -sT: Run a TCP connect scan, rather than a SYN scan. This is slower, but does not require elevated
        //       priviliges, and simply asks the OS to open a TCP connection to the host. It's also more likely
        //       to be detected and blocked.
        //  -PN: Skip the ping scan and host detection step entirely, and just assume the host is up
        //  -T2: Use the "polite" timing profile using less bandwidth and host resources
        //  -p:  The port number (or range) to scan
        $output = $this->processFactory->get(['nmap', '-sT', '-PN', '-T2', '-p', $port, $host])->mustRun()->getOutput();
        return (strpos($output, "${port}/tcp open") !== false);
    }

    /**
     * Scans a host to see if a given UDP port is open
     *
     * @param string $host The hostname or IP of the host to scan
     * @param int $port The UDP port number to check
     *
     * @return bool True if the port is open
     */
    public function udpPortScan(string $host, int $port): bool
    {
        // NMAP Argument Docs:
        //  -sU: Run a UDP Port Scan
        //  -PN: Skip the ping scan and host detection step entirely, and just assume the host is up
        //  -T2: Use the "polite" timing profile using less bandwidth and host resources
        //  -p:  The port number (or range) to scan
        $output = $this->processFactory->get(['nmap', '-sU', '-PN', '-T2', '-p', $port, $host])->mustRun()->getOutput();
        // The tailing space here is important to avoid matching on "open|filtered"
        return (strpos($output, "${port}/udp open ") !== false);
    }
}
