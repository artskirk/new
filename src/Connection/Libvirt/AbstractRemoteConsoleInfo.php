<?php

namespace Datto\Connection\Libvirt;

abstract class AbstractRemoteConsoleInfo
{
    private string $host;
    private ?int $port;

    public function __construct(
        string $host,
        ?int $port
    ) {
        $this->host = $host;
        $this->port = $port;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function setHost(string $host)
    {
        $this->host = $host;
    }

    public function setPort(int $port)
    {
        $this->port = $port;
    }

    /**
     * Get the type of this connection as a string
     *
     * @return string
     */
    abstract public function getType(): string;

    /**
     * Get all the values for this connection type, including extra ones
     *
     * @return array
     *      Array of the values contained in this info
     */
    abstract public function getValues(): array;

    /**
     * Set a non-standard extra value for this connection type
     *
     * @param string $key
     * @param mixed $value
     */
    abstract public function setExtra(string $key, $value);
}
