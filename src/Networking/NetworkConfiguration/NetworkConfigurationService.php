<?php

namespace Datto\Networking\NetworkConfiguration;

use Datto\Util\NetworkSystem;
use Exception;

/**
 * Class to provide Network Provisioning and testing services
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class NetworkConfigurationService
{
    /**
     * 10 seccond timeout testing if port is available
     */
    const CONNECTIVITY_TIMEOUT_SECONDS = 10;

    /**
     * Path to test http url
     */
    const  HTTP_TEST_URL = 'test80.dattobackup.com';

    /**
     * Path to test https url
     */
    const  HTTPS_TEST_URL = 'test443.dattobackup.com';

    /**
     * http port
     */
    const  HTTP_PORT = 80;

    /**
     * http port
     */
    const  HTTPS_PORT = 443;

    /** @var NetworkSystem */
    private $networkSystem;

    /**
     * Constructor with optional dependency injection.
     *
     * @param NetworkSystem|null $filesystem
     */
    public function __construct(
        NetworkSystem $networkSystem = null
    ) {
        $this->networkSystem = $networkSystem ?: new NetworkSystem();
    }

    /**
     * Return true if eth0 can connect to device.dattbackup.com on http and https ports
     *
     * @return bool
     */
    public function testNetworkConnection()
    {
        $httpConnected = $this->isPortOpen(
            static::HTTP_TEST_URL,
            static::HTTP_PORT,
            static::CONNECTIVITY_TIMEOUT_SECONDS
        );
        $httpsConnected = $this->isPortOpen(
            static::HTTPS_TEST_URL,
            static::HTTPS_PORT,
            static::CONNECTIVITY_TIMEOUT_SECONDS
        );
        return $httpConnected && $httpsConnected;
    }

    /**
     * Return true if port is open on the given url Otherwise, return false
     *
     * @param string $url
     * @param int $port
     * @param int $timeout
     * @return bool
     */
    private function isPortOpen($url, $port, $timeout = 10)
    {
        $handle = $this->networkSystem->fsockopen(
            $url,
            $port,
            $errno,
            $errstr,
            $timeout
        );
        if ($handle) {
            $this->networkSystem->fclose($handle);
        }
        return (bool)$handle;
    }
}
