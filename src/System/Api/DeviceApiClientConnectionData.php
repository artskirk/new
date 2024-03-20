<?php

namespace Datto\System\Api;

use Exception;

/**
 * Contains all the information necessary to establish and maintain an HTTP
 * connection to a single remote SIRIS device.  This includes the following:
 *
 *  1. Device Identification               hostname
 *  2. Authentication Credentials          username, password
 *  3. Communications protocol             https
 *
 * This class is immutable.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class DeviceApiClientConnectionData
{
    /** @var string */
    private $hostname;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var bool */
    private $https;

    /** @var string|null */
    private $sshIp;

    /**
     * @param string $hostname
     * @param string $username
     * @param string $password
     * @param bool $https
     * @param string|null $sshIp
     */
    public function __construct(
        string $hostname,
        string $username,
        string $password,
        bool $https,
        string $sshIp = null
    ) {
        if ($hostname === '') {
            throw new Exception("Hostname cannot be blank.");
        }

        if ($username === '') {
            throw new Exception("Username cannot be blank.");
        }

        $this->hostname = $hostname;
        $this->username = $username;
        $this->password = $password;
        $this->https = $https;
        $this->sshIp = $sshIp;
    }

    /**
     * Gets the hostname for this connection.
     *
     * @return string
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * Gets the username for this connection.
     *
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Gets the password for this connection.
     *
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Gets the HTTPS flag for this connection.
     *
     * @return bool
     */
    public function isHttps(): bool
    {
        return $this->https;
    }

    /**
     * The secondary IP address to use for bulk data transfers over SSH.
     *
     * If not set, the getHostname() value should be used.
     *
     * @return string|null
     */
    public function getSshIp()
    {
        return $this->sshIp;
    }

    /**
     * Returns a serialized string representing the connection data.
     *
     * @return string
     */
    public function serialize(): string
    {
        return json_encode([
            'hostname' => $this->hostname,
            'username' => $this->username,
            'password' => base64_encode($this->password),  // make it a LITTLE harder to read
            'https' => $this->https,
            'sshIp' => $this->sshIp,
        ]);
    }

    /**
     * Returns a serialized string representing the connection data.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->serialize();
    }

    /**
     * Creates a new DeviceApiClientConnectionData from a serialized string.
     *
     * @param string $connectionData
     * @return DeviceApiClientConnectionData
     */
    public static function unserialize(string $connectionData): DeviceApiClientConnectionData
    {
        $array = json_decode($connectionData, true);
        if ($array !== null && isset($array['hostname']) && isset($array['username']) && isset($array['password'])) {
            $password = base64_decode($array['password']);
            if ($password !== false) {
                return new DeviceApiClientConnectionData(
                    $array['hostname'],
                    $array['username'],
                    $password,
                    $array['https'] ?? false,
                    $array['sshIp'] ?? null
                );
            }
        }

        throw new Exception('Invalid connection data.');
    }
}
