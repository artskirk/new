<?php

namespace Datto\Winexe;

/**
 * Factory class for WinexeApi instances
 * @author Jason Lodice <jlodice@datto.com>
 */
class WinexeApiFactory
{
    /**
     * Create new WinexeApi instance
     *
     * @param string $hostName
     * @param string $user
     * @param string $password
     * @param string|null $domain
     * @return WinexeApi
     */
    public function create(string $hostName, string $user, string $password, string $domain = null)
    {
        $winexe = new Winexe($hostName, $user, $password, $domain);
        return new WinexeApi($winexe);
    }
}
