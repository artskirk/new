<?php

namespace Datto\RemoteWeb;

use Datto\Config\DeviceConfig;

/**
 * Service for working with RemoteWeb configuration settings
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class RemoteWebService
{
    /** @var DeviceConfig */
    private $deviceConfig;

    public function __construct(DeviceConfig $deviceConfig)
    {
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * @return bool Whether remote web forces the user to log in when connecting through the partner portal
     */
    public function getForceLogin()
    {
        return $this->deviceConfig->has('remoteWebForceLogin');
    }

    /**
     * @param bool $remoteWebForceLogin Whether remote web forces the user to log in through the partner portal
     */
    public function setForceLogin(bool $remoteWebForceLogin)
    {
        $this->deviceConfig->set('remoteWebForceLogin', $remoteWebForceLogin);
    }

    /**
     * @return string The public IP address of the client where requests are originating from
     */
    public static function getRemoteIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    }

    /**
     * @return bool whether the current request is coming over RLY
     */
    public static function isRlyRequest(): bool
    {
        return preg_match('/^\S+-rly-relay-\S+\.datto\.com$/', $_SERVER['HTTP_X_FORWARDED_SERVER'] ?? '');
    }

    /**
     * Returns the remote host (via. local or remote web)
     */
    public static function getRemoteHost(): string
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1') {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
