<?php

namespace Datto\Util;

/**
 * Wrapper for basic Network operations.
 *
 * @author John Fury Christ <jchrist@datto.com>
 * @codeCoverageIgnore
 */
class NetworkSystem
{
    /**
     * Returns a file pointer which may be used together with the other file
     * functions (such as fgets(), fgetss(), fwrite(), fclose(), and feof()).
     * If the call fails, it will return FALSE
     *
     * @param string $hostname
     * @param int $port
     * @param int $errorNumber
     * @param string $errrorString
     * @param float $timeout
     * @return resource|boolean
     */
    public function fsockopen($hostname, $port = -1, &$errorNumber = null, &$errrorString = null, $timeout = 10)
    {
        return @fsockopen($hostname, $port, $errorNumber, $errrorString, $timeout);
    }

    /**
     * The file pointed to by handle is closed.
     * Returns TRUE on success or FALSE on failure.
     *
     * @param resource $handle
     * @return boolean
     */
    public function fclose($handle)
    {
        return @fclose($handle);
    }

    /**
     * Gets the host name of the local machine.
     *
     * @return string Hostname on success, false otherwise
     */
    public function getHostName()
    {
        return gethostname();
    }

    /**
     * Check DNS records corresponding to a given Internet host name or IP address.
     *
     * @param string $host Host IP address or host name.
     * @param string $type Type of DNS record
     * @return bool True if any records are found, False otherwise
     */
    public function checkDnsRR($host, $type = null)
    {
        return checkdnsrr($host, $type);
    }

    /**
     * Fetch DNS Resource Records associated with a given hostname.
     *
     * @param string $hostname A valid DNS hostname
     * @param int $type Type of DNS record
     * @return array Array of associative arrays for each record found
     */
    public function dnsGetRecord($hostname, $type = DNS_A)
    {
        return dns_get_record($hostname, $type);
    }

    /**
     * Get the IPv4 address corresponding to a given Internet hostname.
     *
     * @param string $hostname The hostname
     * @return string The IPv4 address of the given hostname
     */
    public function getHostByName($hostname)
    {
        return gethostbyname($hostname);
    }
}
