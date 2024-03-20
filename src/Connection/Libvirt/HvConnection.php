<?php

namespace Datto\Connection\Libvirt;

use Datto\Connection\ConnectionType;

/**
 * Represents Hyper-V hypervisor connection.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class HvConnection extends AbstractAuthConnection
{
    /**
     * Constructor
     *
     * @param string $name
     */
    public function __construct($name)
    {
        parent::__construct(ConnectionType::LIBVIRT_HV(), $name);
    }

    /**
     * {@inheritdoc}
     */
    protected function buildUri()
    {
        if (false === $this->isValid()) {
            return '';
        }

        $extraParameters = $this->buildExtraParameters();

        $port = '';
        if ($this->getPort() > 0) {
            $port = ':' . $this->getPort();
        }

        $this->uri = sprintf(
            'hyperv://%s%s/%s',
            $this->getHostname(),
            $port,
            $extraParameters
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isValid()
    {
        // hostname must be non-empty string
        $hostname = $this->getHostname();
        if (empty($hostname)) {
            return false;
        }

        // user name must be string or null
        $user = $this->getUser();
        $password = $this->getPassword();
        if (empty($user) || empty($password)) {
            return false;
        }

        return true;
    }

    /**
     * Returns the hostname for this connection
     *
     * @return string|null
     */
    public function getHostname()
    {
        return $this->getKey('hostname');
    }

    /**
     * {@inheritdoc}
     */
    public function getHost(): string
    {
        return $this->getHostname();
    }

    /**
     * @param string $hostname
     */
    public function setHostname($hostname)
    {
        $this->setKey('hostname', $hostname);
    }

    /**
     * Get the domain.
     *
     * @return string|null
     */
    public function getDomain()
    {
        return $this->getKey('domain');
    }

    /**
     * Set the domain.
     *
     * @param string $domain
     */
    public function setDomain($domain)
    {
        $this->setKey('domain', $domain);
    }

    /**
     * Use plain http protocol
     *
     * @return bool
     */
    public function isHttp()
    {
        return (bool) $this->getKey('http');
    }

    /**
     * Set use "plain" http protocol.
     *
     * @param bool $enabled
     */
    public function setHttp($enabled)
    {
        $this->setKey('http', (bool) $enabled);
    }

    /**
     * Get the port number.
     *
     * @return int|null
     */
    public function getPort()
    {
        return $this->getKey('port');
    }

    /**
     * Set the port number
     *
     * @param int $port
     */
    public function setPort($port)
    {
        $this->setKey('port', $port);
    }

    /**
     * Get the hypervisor version.
     *
     * @return string|null
     */
    public function getHypervisorVersion()
    {
        return $this->getKey('hypervisorVersion');
    }

    /**
     * Set the hypervisor version
     *
     * @param string $version
     */
    public function setHypervisorVersion($version)
    {
        $this->setKey('hypervisorVersion', $version);
    }

    /**
     * {@inheritdoc}
     */
    public function supportsScreenshots()
    {
        $version = $this->getHypervisorVersion();

        // Only 2k12R2 and newer
        if ($version !== null && version_compare($version, '6.3', '>=')) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        // Translate our connectionParams array to match what our setCorrectionParams method (formatted for UI) expects
        $array['connectionParams']['server'] = $array['connectionParams']['hostname'];

        return $array;
    }

    /**
     * @return string
     */
    private function buildExtraParameters()
    {
        $params = '';

        if ($this->isHttp()) {
            $params = '?transport=http';
        }

        return $params;
    }
}
