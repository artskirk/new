<?php

namespace Datto\Virtualization;

use Datto\Lakitu\Client\Transport\TcpNetworkSocket;

/**
 * Base class for virtual machines using remote hypervisors
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
abstract class RemoteVirtualMachine extends VirtualMachine
{
    /**
     * {@inheritdoc}
     */
    public function addSerialPort(int $comPortNumber = 1)
    {
        $serialPort = $this->getDomainXml()->devices->serial;

        if ($serialPort->getName() !== 'serial') {
            throw new \Exception('Serial port missing on VM: ' . $this->getName());
        }

        $host = (string) $serialPort->source['host'];
        $port = (string) $serialPort->source['service'];
        $socketAddress = $host . ':' . $port;

        $this->internalAddSerialPort($comPortNumber, $socketAddress);
    }

    /**
     * {@inheritdoc}
     */
    public function createSerialTransport(int $comPortNumber = 1)
    {
        list($address, $port) = explode(':', $this->getSerialPort($comPortNumber));
        $tcpNetworkSocket = new TcpNetworkSocket();
        $tcpNetworkSocket->setAddress($address, (int)$port);
        return $tcpNetworkSocket;
    }

    /**
     * Updates the current number of virtual CPUs for this VM
     *
     * @param int $cpuCount
     * @return mixed
     */
    public function updateCpuCount($cpuCount)
    {
        // TODO: Implement updateCpuCount() method.
    }

    /**
     * Updates the current amount of memory allocated for this VM
     *
     * @param int $memory
     * @return mixed
     */
    public function updateMemory($memory)
    {
        // TODO: Implement updateMemory() method.
    }

    /**
     * Updates the current storage controller for this VM
     *
     * @param string $controller
     * @return mixed
     */
    public function updateStorageController($controller)
    {
        // TODO: Implement updateStorageController() method.
    }

    /**
     * Updates the current network settings for this VM
     *
     * @param string $nicType
     *   The network mode that should be used
     * @param $networkController
     *   The network controller
     * @return mixed
     */
    public function updateNetwork($nicType, $networkController)
    {
        // TODO: Implement updateNetwork() method.
    }

    /**
     * Updates the video driver for this VM
     *
     * @param string $videoController
     *   The new video controller that should be used
     * @return mixed
     */
    public function updateVideo($videoController)
    {
        // TODO: Implement updateVideo() method.
    }
}
