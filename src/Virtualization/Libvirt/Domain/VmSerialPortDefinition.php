<?php

namespace Datto\Virtualization\Libvirt\Domain;

/**
 * Represents the Libvirt Domain XML <serial> element.
 *
 * Refer to the Libvirt documentation for information on serial port definitions:
 * {@link http://libvirt.org/formatdomain.html#elementsCharHostInterface}
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class VmSerialPortDefinition
{
    /**
     * @const string TYPE_TCP
     *   TCP network socket backend for virtual serial port
     */
    const TYPE_TCP = 'tcp';

    /**
     * @const string TYPE_UNIX
     *   Unix domain socket backend for virtual serial port
     */
    const TYPE_UNIX = 'unix';

    /**
     * @const string MODE_SERVER
     *   Server mode for TCP network socket
     */
    const MODE_SERVER = 'bind';

    /**
     * @const int TCP_PORT
     *   TCP port number for virtual serial port communications
     */
    const TCP_PORT = 25570;

    /**
     * @const string PROTOCOL_RAW
     *   Raw TCP protocol type
     */
    const PROTOCOL_RAW = 'raw';

    /**
     * @const int COM_1
     *   COM 1 serial port number
     */
    const COM_1 = 1;

    /**
     * @var string $type
     *   Type of serial port backend, TCP network socket or Unix domain socket
     */
    protected $type;

    /**
     * @var int $port
     *   COM port number, identifier for the virtual serial port
     */
    protected $port;

    /**
     * @var string $path
     *   Path to Unix domain socket file
     */
    protected $path;

    /**
     * @var string $host
     *   Address of TCP network socket host
     */
    protected $host;

    /**
     * Get serial port type.
     *
     * @return string
     *  One of the TYPE_* string constants.
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set serial port type.
     *
     * @param string $type
     *  One of the TYPE_* string constants.
     *
     * @return self
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Get the COM port on which the serial port listens.
     *
     * @return string
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Set the COM port on which the serial port listens.
     *
     * @param string $port
     *
     * @return self
     */
    public function setPort($port)
    {
        $this->port = $port;
        return $this;
    }

    /**
     * Get the Unix domain socket file path on which the serial port listens.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Set the Unix domain socket file path on which the serial port listens.
     *
     * @param string $path
     *
     * @return self
     */
    public function setPath($path)
    {
        $this->path = $path;
        return $this;
    }

    /**
     * Get the TCP network socket host on which the serial port listens.
     *
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Set the TCP network socket host on which the serial port listens.
     *
     * @param string $host
     *
     * @return self
     */
    public function setHost($host)
    {
        $this->host = $host;
        return $this;
    }

    /**
     * Override __toString magic method to output Libvirt domain XML for <serial> element
     *
     * @return string
     */
    public function __toString()
    {
        $root = new \SimpleXmlElement('<root></root>');

        $serial = $root->addChild('serial');
        $serial->addAttribute('type', $this->type);

        $source = $serial->addChild('source');
        $source->addAttribute('mode', self::MODE_SERVER);

        if ($this->type === self::TYPE_TCP) {
            $source->addAttribute('host', $this->host);
            $source->addAttribute('service', self::TCP_PORT);

            $protocol = $serial->addChild('protocol');
            $protocol->addAttribute('type', self::PROTOCOL_RAW);
        } else {
            $source->addAttribute('path', $this->path);
        }

        $target = $serial->addChild('target');
        $target->addAttribute('port', $this->port - 1);

        return $root->serial->asXml();
    }
}
