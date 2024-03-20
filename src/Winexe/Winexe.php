<?php

namespace Datto\Winexe;

/**
 * A data object that holds parameters needed by the winexe command.
 *
 * @author John Fury Christ <furychrist@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class Winexe
{
    /** @var string $remoteHost */
    private $remoteHost;
    /** @var string $domainName */
    private $domainName;
    /** @var string $userName */
    private $userName;
    /** @var string $password */
    private $password;

    /**
     * constructor
     *
     * @param string $remoteHost hostname or IP
     * @param string $userName user name for authentication
     * @param string $password password for authentication
     * @param string|null $domainName domain name for authentication
     */
    public function __construct(
        $remoteHost,
        $userName,
        $password,
        $domainName = null
    ) {
        $this->remoteHost = $remoteHost;
        $this->userName = $userName;
        $this->password = $password;
        $this->domainName = $domainName;
    }

    /**
     * IP or hostname on which to run command
     *
     * @return string
     */
    public function getRemoteHost()
    {
        return $this->remoteHost;
    }

    /**
     * Domain name of host if host is on a domain
     *
     * @return string|null
     */
    public function getDomainName()
    {
        return $this->domainName;
    }

    /**
     * user name for login credentials
     *
     * @return string
     */
    public function getUserName()
    {
        return $this->userName;
    }

    /**
     * password for login credentials
     *
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }
}
