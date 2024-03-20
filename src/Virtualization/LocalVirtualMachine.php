<?php

namespace Datto\Virtualization;

use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Lakitu\Client\Transport\UnixDomainSocket;
use Datto\Service\Security\FirewallService;
use Datto\Util\XmlUtil;
use Datto\Virtualization\Libvirt\Domain\VmGraphicsDefinition;
use Datto\Virtualization\Libvirt\Domain\VmNetworkDefinition;
use Datto\Virtualization\Libvirt\Domain\VmVideoDefinition;
use Datto\Log\DeviceLoggerInterface;
use RuntimeException;
use SimpleXMLElement;

/**
 * Configuration for a Virtual machine hosted by a hypervisor process running
 * locally on a physical Datto appliance.
 * (Currently, this refers to virtual machines on KVM)
 *
 * @author Matt Coleman <mcoleman@datto.com>
 */
class LocalVirtualMachine extends VirtualMachine
{
    /**
     * @const string
     *   Directory containing sockets for VM serial port connections.
     */
    const SOCKET_DIR = '/run/lakitu';

    /**
     * @param string $name
     * @param string $uuid
     * @param string $storageDir
     * @param AbstractLibvirtConnection $connection
     *   Connection to the hypervisor
     * @param Filesystem $filesystem
     * @param Sleep $sleep
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        string $name,
        string $uuid,
        string $storageDir,
        AbstractLibvirtConnection $connection,
        Filesystem $filesystem,
        Sleep $sleep,
        DeviceLoggerInterface $logger,
        FirewallService $firewallService
    ) {
        $this->assertConnectionType(ConnectionType::LIBVIRT_KVM(), $connection->getType());
        parent::__construct($name, $uuid, $storageDir, $connection, $filesystem, $sleep, $logger, $firewallService);
    }

    /**
     * {@inheritdoc}
     */
    public function addSerialPort(int $comPortNumber = 1)
    {
        $this->getFilesystem()->mkdirIfNotExists(self::SOCKET_DIR, false, 0700);

        if (!$this->getFilesystem()->isWritable(self::SOCKET_DIR)) {
            throw new \Exception('Unable to write to socket directory: ' . self::SOCKET_DIR);
        }

        /** @var SimpleXMLElement $serialPort */
        $serialPort = $this->getDomainXml()->devices->serial;

        if ($serialPort->getName() !== 'serial') {
            throw new \Exception('Serial port missing on VM: ' . $this->getName());
        }

        $socketPath = (string) $serialPort->source['path'];

        $this->internalAddSerialPort($comPortNumber, $socketPath);
    }

    /**
     * {@inheritdoc}
     */
    public function createSerialTransport(int $comPortNumber = 1)
    {
        $address = $this->getSerialPort($comPortNumber);
        $unixDomainSocket = new UnixDomainSocket();
        $unixDomainSocket->setAddress($address, $comPortNumber);
        return $unixDomainSocket;
    }

    /**
     * Updates the current number of virtual CPUs for this VM
     *
     * @param int $cpuCount
     * @return VirtualMachine
     */
    public function updateCpuCount($cpuCount)
    {
        $this->getDomainXml()->vcpu = $cpuCount;
        $this->getDomainXml()->cpu->topology['cores'] = $cpuCount;
        $this->getDomainXml()->cpu->topology['sockets'] = '1';
        $this->getDomainXml()->cpu->topology['threads'] = '1';
        $this->getDomainXml()->cpu->topology['dies'] = '1';
        return $this;
    }

    /**
     * Updates the current amount of memory allocated for this VM
     *
     * @param int $memory
     * @return mixed
     */
    public function updateMemory($memory)
    {
        $this->getDomainXml()->memory["unit"] = "MiB";
        $this->getDomainXml()->currentMemory["unit"] = "MiB";
        $this->getDomainXml()->currentMemory = $memory;
        $this->getDomainXml()->memory = $memory;
        return $this;
    }

    /**
     * Updates the current storage controller for this VM
     *
     * @param string $controller
     * @return mixed
     */
    public function updateStorageController($controller)
    {
        foreach ($this->getDomainXml()->devices->disk as $disk) {
            $disk->target["bus"]  = $controller;

            /*
             * Libvirt automatically sets up the address for you,
             * so it's necessary to remove it from the XML in order to
             * generate a new one.
             */
            unset($disk->address);
        }
        return $this;
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
        // Removes the current network interfaces from the domain
        $this->removeDevice($this->getDomainXml()->devices->interface);
        unset($this->getDomainXml()->devices->interface);

        if ($nicType !== 'NONE') {
            $nicType = strtolower($nicType);

            if (preg_match('/^bridge-/', $nicType)) {
                $bridgeTo = preg_replace('/^bridge-/', '', $nicType);
                $nicType = 'bridge';
            }

            if ('nat' == $nicType) {
                $nicType = "network";
            }

            $vmNetwork = new VmNetworkDefinition();
            $vmNetwork->setInterfaceType($nicType);
            $vmNetwork->setInterfaceModel($networkController);

            switch ($nicType) {
                case VmNetworkDefinition::INTERFACE_TYPE_BRIDGE:
                    $vmNetwork->setSourceBridge($bridgeTo);
                    break;
                case VmNetworkDefinition::INTERFACE_TYPE_INTERNAL:
                    $vmNetwork->setInterfaceType(VmNetworkDefinition::INTERFACE_TYPE_NETWORK);
                    $vmNetwork->setSourceNetwork(VmNetworkDefinition::ISOLATED_NETWORK_NAME);
                    break;
                case VmNetworkDefinition::INTERFACE_TYPE_NETWORK:
                    $vmNetwork->setSourceNetwork(VmNetworkDefinition::DEFAULT_NETWORK_NAME);
                    $vmNetwork->addFilter(VmNetworkDefinition::DEFAULT_NETWORKFILTER_NAME);
                    break;
            }

            XmlUtil::addChildXml($this->getDomainXml()->devices[0], new SimpleXMLElement((string) $vmNetwork));

            if ($this->isRunning()) {
                $this->attachDevice($this->getDomainXml()->devices->interface->asXML());
            }
        }

        return $this;
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
        $lv = $this->getConnection()->getLibvirt();
        $isVMRunning = $this->isRunning();
        $dom = $this->getLibvirtDom();
        $vncPort = $lv->domainGetVncPort($dom);

        // if the desired videoController already exists, do nothing
        $videoTypes = $this->getDomainXml()->xpath("//video/model[@type='$videoController']");
        if (count($videoTypes) > 0) {
            return $this;
        }

        $vmVideo = new VmVideoDefinition();
        if ($videoController === $vmVideo::MODEL_VGA) {
            $vmVideo->setModel($vmVideo::MODEL_VGA);
            $vmVideo->setVramKib(65536); // 64
        } elseif ($videoController === $vmVideo::MODEL_CIRRUS) {
            $vmVideo->setModel($vmVideo::MODEL_CIRRUS);
            $vmVideo->setVramKib(16384); // CIRRUS displays use 16
        } else {
            return $this; // not a driver we recognise so abandon before we make changes
        }

        // Remove the current graphics and video interfaces from the domain
        $this->removeDevice($this->getDomainXml()->devices->video);
        unset($this->getDomainXml()->devices->video);

        $this->removeDevice($this->getDomainXml()->devices->graphics);
        unset($this->getDomainXml()->devices->graphics);

        // add VNC output first so that virt-manager always shows pictures
        $vmVncGraphics = new VmGraphicsDefinition();
        $vmVncGraphics->setType($vmVncGraphics::TYPE_VNC);
        $vmVncGraphics->setPort($vncPort);
        $vmVncGraphics->setListen(array(
            'type' => 'address',
            'address' => '0.0.0.0'
        ));

        XmlUtil::addChildXml($this->getDomainXml()->devices[0], new SimpleXMLElement((string) $vmVncGraphics));
        if ($isVMRunning) {
            $this->attachDevice($this->getDomainXml()->devices->graphics->asXML());
        }

        XmlUtil::addChildXml($this->getDomainXml()->devices[0], new SimpleXMLElement((string) $vmVideo));
        if ($isVMRunning) {
            $this->attachDevice($this->getDomainXml()->devices->video->asXML());
        }
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function updateVm()
    {
        // libvirt filters out secrets, so to preserve the VNC password we explicitly copy it
        $libvirt = $this->getConnection()->getLibvirt();
        $dom = $libvirt->getDomainObject($this->getUuid());

        // get passwd attribute of vnc graphics node
        $passwords = $libvirt->getXpath($dom, '//graphics[@type="vnc"][1]/@passwd', false, true);

        if (sizeof($passwords) > 0) {
            foreach ($this->getDomainXml()->devices->graphics as $graphics) {
                if ($graphics['type'] == VmGraphicsDefinition::TYPE_VNC) {
                    $graphics->addAttribute('passwd', $passwords[0]);
                }
            }
        }

        if (!$libvirt->domainDefine($this->getDomainXml()->asXML())) {
            throw new RuntimeException($libvirt->getLastError());
        }

        // refresh domainXml with newly updated VM info
        $this->initDomainXml();

        return parent::updateVm();
    }

    /**
     * Remove the specified device using libvirt.
     *
     * @param SimpleXMLElement $device
     * @return bool
     */
    private function removeDevice(SimpleXMLElement $device)
    {
        if (!empty($device->asXML()) && $this->isRunning()) {
            $this->detachDevice($device->asXML());
            return true;
        }
        return false;
    }

    /**
     * Attach a network device while the VM is running
     *
     * @param string $device
     * @return bool
     */
    private function attachDevice(string $device): bool
    {
        $lv = $this->getConnection()->getLibvirt();
        $dom = $lv->getDomainObject($this->getUuid());

        if ($dom) {
            return $lv->attachDomainDevice($dom, $device);
        }

        return false;
    }

    /**
     * Detach a network device while the VM is running
     *
     * @param string $device
     * @return bool
     */
    private function detachDevice(string $device): bool
    {
        $lv = $this->getConnection()->getLibvirt();
        $dom = $lv->getDomainObject($this->getUuid());

        if ($dom) {
            return $lv->detachDomainDevice($dom, $device);
        }

        return false;
    }
}
