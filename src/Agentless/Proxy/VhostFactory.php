<?php

namespace Datto\Agentless\Proxy;

use Vmwarephp\Vhost;

/**
 * DESCRIPTION
 *
 * @author Mario Rial <mrial@datto.com>
 */
class VhostFactory
{
    private static $vhost = [];

    public function __construct()
    {
    }

    /**
     * @param string $host
     * @param string $user
     * @param string $password
     * @return Vhost
     */
    public function get(string $host, string $user, string $password)
    {
        $arrayKey = $this->getVhostArrayKey($host, $user, $password);
        if (!isset(static::$vhost[$arrayKey])) {
            static::$vhost[$arrayKey] = new Vhost($host, $user, $password);
        }

        return static::$vhost[$arrayKey];
    }

    /**
     * @param string $host
     * @param string $user
     * @param string $password
     * @return string
     */
    private function getVhostArrayKey(string $host, string $user, string $password)
    {
        return md5($host . $user . $password);
    }
}
