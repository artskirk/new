<?php

namespace Datto\Virtualization;

use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\AbstractRemoteConsoleInfo;
use Datto\Lakitu\Client\Transport\AbstractTransportClient;
use Datto\Restore\Virtualization\ConsoleType;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Service\Security\FirewallService;
use InvalidArgumentException;
use Datto\Log\DeviceLoggerInterface;
use RuntimeException;
use SimpleXMLElement;

/**
 * Virtual machine configuration and metadata.
 *
 * @author Matt Coleman <mcoleman@datto.com>
 */
abstract class VirtualMachine
{
    const KEYS_CTRL = [0x1d];
    const KEYS_ALT_TAB = [0x38, 0x0f];
    const KEYS_CTRL_ALT_DEL = [0x1d, 0x38, 0x53];
    const ACPI_SHUTDOWN_WAIT = 90;
    const HARD_SHUTDOWN_WAIT = 10;

    /** @var  string $storageDir
     * The path to the ZFS clone that backs the virtual machine.
     */
    private $storageDir;

    /**
     * @var array $serialPorts
     *   Maps VM COM port numbers (array keys) to socket paths (array values).
     */
    private $serialPorts;

    /**
     * @var AbstractLibvirtConnection $connection
     *   Connection to hypervisor
     */
    private $connection;

    /** @var Filesystem $filesystem */
    private $filesystem;

    /**
     * @var SimpleXMLElement $domainXml
     *  XML representation of VM configuration
     */
    private $domainXml;

    /** @var string */
    private $name;

    /** @var string */
    private $uuid;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** libvirt domain resource */
    private $libvirtDom;

    /** @var Sleep */
    protected $sleep;

    private FirewallService $firewallService;

    /**
     * @param string $name
     * @param string $uuid
     * @param string $storageDir
     * @param AbstractLibvirtConnection $connection
     * @param Filesystem $filesystem
     * @param Sleep $sleep
     * @param DeviceLoggerInterface $logger
     */
    protected function __construct(
        string $name,
        string $uuid,
        string $storageDir,
        AbstractLibvirtConnection $connection,
        Filesystem $filesystem,
        Sleep $sleep,
        DeviceLoggerInterface $logger,
        FirewallService $firewallService
    ) {
        if (empty($uuid)) {
            throw new InvalidArgumentException("Parameter uuid must be non empty");
        }

        $this->name = $name;
        $this->uuid = $uuid;
        $this->storageDir = $storageDir;
        $this->connection = $connection;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->sleep = $sleep;
        $this->firewallService = $firewallService;
    }

    /**
     * Initialize our cached copy of the libvirt domain xml
     */
    protected function initDomainXml()
    {
        $uuid = $this->getUuid();
        $libvirt = $this->connection->getLibvirt();
        $dom = $libvirt->getDomainObject($uuid);

        if (!$dom) {
            throw new RuntimeException("Cannot get Libvirt domain for uuid='$uuid'. {$libvirt->getLastError()}");
        }

        $xml = $libvirt->domainGetXml($dom, true);
        if (!$xml) {
            throw new RuntimeException("Cannot get Libvirt domain xml for uuid='$uuid'. {$libvirt->getLastError()}");
        }

        $this->domainXml = new SimpleXMLElement($xml);
    }

    /**
     * Add a serial port to the VM.
     *
     * Uses standard COM port address and IRQ values.
     *
     * @param int $comPortNumber
     *   The COM port to add.
     */
    abstract public function addSerialPort(int $comPortNumber = 1);

    /**
     * Updates the current number of virtual CPUs for this VM
     *
     * @param int $cpuCount
     * @return mixed
     */
    abstract public function updateCpuCount($cpuCount);

    /**
     * Updates the current amount of memory allocated for this VM
     *
     * @param int $memory
     * @return mixed
     */
    abstract public function updateMemory($memory);

    /**
     * Updates the current storage controller for this VM
     *
     * @param string $controller
     * @return mixed
     */
    abstract public function updateStorageController($controller);

    /**
     * Updates the current network settings for this VM
     *
     * @param string $nicType
     *   The network mode that should be used
     * @param $networkController
     *   The network controller
     * @return mixed
     */
    abstract public function updateNetwork($nicType, $networkController);

    /**
     * Updates the video driver for this VM
     *
     * @param string $videoController
     *   The new video controller that should be used
     * @return mixed
     */
    abstract public function updateVideo($videoController);

    /**
     * Get the socket path for a given serial port.
     *
     * @param int $comPortNumber
     *   The COM port whose socket path you'd like to retrieve.
     *
     * @return string
     *   The path to the socket for the given COM port.
     */
    public function getSerialPort(int $comPortNumber)
    {
        return $this->serialPorts[$comPortNumber];
    }

    /**
     * Create a Transport object for the VM's serial port.
     *
     * @param int $comPortNumber
     *   The COM port whose socket path you'd like to retrieve.
     *
     * @return AbstractTransportClient
     *   A transport object for communicating with the VM's serial port.
     */
    abstract public function createSerialTransport(int $comPortNumber = 1);

    /**
     * Get the connection associated with this VM.
     *
     * @return AbstractLibvirtConnection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Updates the VM based on the changes made to domainXml
     *
     * @return bool
     */
    public function updateVm()
    {
        return true;
    }

    /**
     * Get the full filesystem path to the mounted ZFS clone for the virtual machine.
     *
     * @return string
     */
    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    /**
     * Start the VM
     */
    public function start()
    {
        $uuid = $this->getUuid();
        $this->logger->info('VMX0835 VM start requested', ['uuid' => $uuid]); // log code is used by device-web see DWI-2252

        if ($this->isRunning()) {
            return;
        }

        $libvirt = $this->getConnection()->getLibvirt();
        $dom = $this->getLibvirtDom();

        $ret = $libvirt->domainStart($dom);
        if (false === $ret) {
            throw new RuntimeException("Libvirt domain '$uuid' failed to start: {$libvirt->getLastError()}");
        }

        $vncPort = $libvirt->domainGetVncPort($dom);

        if ($this->connection->isLocal() && is_int($vncPort)) {
            $this->firewallService->open($vncPort, FirewallService::DATTO_ZONE);
        }
    }

    /**
     * Perform a hard stop of the VM
     */
    public function stop()
    {
        $uuid = $this->getUuid();
        $this->logger->debug('VMX0835 VM stop requested', ['uuid' => $uuid]);
        if ($this->isShutdown()) {
            return;
        }

        $libvirt = $this->getConnection()->getLibvirt();
        $dom = $this->getLibvirtDom();
        $vncPort = $libvirt->domainGetVncPort($dom);

        $this->powerDown($cleanShutdown = false);

        if (!$this->waitForShutdown(self::HARD_SHUTDOWN_WAIT)) {
            $this->logger->warning('VRT0001 VM could not be shutdown', ['uuid' => $uuid]);
        } else {
            if ($this->connection->isLocal() && is_int($vncPort)) {
                $this->firewallService->close($vncPort, FirewallService::DATTO_ZONE);
            }
        }
    }

    /**
     * Perform a restart of the VM.  This will attempt a clean shutdown and then a start.
     */
    public function restart()
    {
        $uuid = $this->getUuid();
        $this->logger->debug("VMX0835 VM restart requested for uuid '$uuid'");

        $this->shutdown();
        $this->start();
    }

    /**
     * Perform a clean shutdown of the VM.
     * If the clean shutdown does not succeed, shutdown will fall back to forcibly shutting down the VM.
     */
    public function shutdown()
    {
        $uuid = $this->getUuid();
        $this->logger->debug('VMX0836 VM shutdown requested', ['uuid' => $uuid]);

        if ($this->isShutdown()) {
            return;
        }

        $powerDownSuccess = $this->powerDown($cleanShutdown = true);

        if ($powerDownSuccess && $this->waitForShutdown(self::ACPI_SHUTDOWN_WAIT)) {
            return;
        }

        $this->logger->warning('VRT0002 VM did not shutdown cleanly, forcing shutdown', ['uuid' => $uuid]);
        $this->stop();
    }

    /**
     * Send the array of XT keycodes to the vm
     *
     * @param array $keyCodes
     */
    public function sendKeyCodes(array $keyCodes)
    {
        $dom = $this->getLibvirtDom();
        $libvirt = $this->connection->getLibvirt();
        if ($libvirt->domainSendKeys($dom, $keyCodes) === false) {
            throw new RuntimeException("Error sending keycodes to vm '{$this->getName()}', uuid '{$this->getUuid()}'");
        }
    }

    /**
     * Gets the VNC port (if VNC is enabled).
     *
     * @return int|bool
     *  The VNC port or false on failure.
     */
    public function getVncPort()
    {
        $dom = $this->getLibvirtDom();
        $libvirt = $this->getConnection()->getLibvirt();
        return $libvirt->domainGetVncPort($dom);
    }

    /**
     * Gets the VNC password (if VNC is enabled).
     *
     * @return string
     *  The VNC port or false on failure.
     */
    public function getVncPassword(): string
    {
        $dom = $this->getLibvirtDom();
        $libvirt = $this->getConnection()->getLibvirt();
        $value = $libvirt->domainGetVncPassword($dom);
        return $value !== false ? $value : '';
    }

    /**
     * Get information about the remote console, adding information to
     * the connection that can only be retrieved at the VM level such
     * as VNC port and password
     *
     * @return AbstractRemoteConsoleInfo|null
     */
    public function getRemoteConsoleInfo(): ?AbstractRemoteConsoleInfo
    {
        $connectionInfo = $this->getConnection()->getRemoteConsoleInfo($this->getName());

        if ($connectionInfo === null) {
            return null;
        }

        if ($connectionInfo->getType() === ConsoleType::VNC) {
            $connectionInfo->setPort($this->getVncPort());
            $connectionInfo->setExtra("password", $this->getVncPassword());
        }

        return $connectionInfo;
    }

    /**
     * Take a screenshot of the vm and save as a jpeg to given path
     *
     * @param string $path path to save the jpeg file
     */
    public function saveScreenshotJpeg(string $path)
    {
        $libvirt = $this->connection->getLibvirt();
        $dom = $this->getLibvirtDom();

        $domainSaveScreenshotJpegResult = $libvirt->domainSaveScreenshotJpeg($dom, $path);
        if ($domainSaveScreenshotJpegResult !== true) {
            $this->logger->error(
                'VMX0834 error saving screenshot jpeg',
                [
                    'error' => $domainSaveScreenshotJpegResult,
                    'path' => $path,
                    'vm' => $this->getName()
                ]
            );
            throw new RuntimeException("Error saving vm '{$this->getName()}' screenshot to path '$path'");
        }
    }

    /**
     * Take a png screenshot of the running vm and return it as raw bytes
     *
     * @param int $width Desired width of the screenshot
     * @return string The byte data of the screenshot png file or ''
     */
    public function getScreenshotBytes(int $width): string
    {
        $libvirt = $this->connection->getLibvirt();
        $dom = $this->getLibvirtDom();
        $screenshot = $libvirt->domainGetScreenshotThumbnail($dom, $width);

        return empty($screenshot) ? '' : $screenshot;
    }

    /**
     * @return bool true if the VM is running
     */
    public function isRunning(): bool
    {
        $libvirt = $this->getConnection()->getLibvirt();
        $dom = $this->getLibvirtDom();
        return $libvirt->domainIsRunning($dom);
    }

    /**
     * @return bool true if the VM is shut down
     */
    public function isShutdown(): bool
    {
        $libvirt = $this->getConnection()->getLibvirt();
        $dom = $this->getLibvirtDom();
        return $libvirt->domainIsShutOff($dom);
    }

    /**
     * Perform a hard or clean shutdown depending on parameter
     *
     * @param bool $cleanShutdown
     * @return bool true if the shutdown was successful
     */
    protected function powerDown(bool $cleanShutdown): bool
    {
        $libvirt = $this->getConnection()->getLibvirt();
        $dom = $this->getLibvirtDom();

        if ($cleanShutdown) {
            $shutdownStatus = $libvirt->domainShutdown($dom);
        } else {
            $shutdownStatus = $libvirt->domainDestroy($dom);
        }

        if (!$shutdownStatus) {
            $errorMessage = $libvirt->getLastError();
            $uuid = $this->getUuid();
            $this->logger->error('VMX0850 Error shutting down VM', ['uuid' => $uuid, 'error' => $errorMessage]);
        }

        return $shutdownStatus;
    }

    /**
     * The name used to register this VM
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The libvirt VM unique identifier
     *
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * @return SimpleXMLElement the libvirt domain xml for this vm
     */
    protected function getDomainXml(): SimpleXMLElement
    {
        if ($this->domainXml === null) {
            $this->initDomainXml();
        }
        return $this->domainXml;
    }

    /**
     * Get libvirt domain resource using the uuid
     *
     * @return resource
     */
    protected function getLibvirtDom()
    {
        if (is_null($this->libvirtDom)) {
            $libvirt = $this->getConnection()->getLibvirt();
            $uuid = $this->getUuid();
            $dom = $libvirt->getDomainObject($uuid);
            if ($dom === false) {
                throw new RuntimeException("Libvirt domain does not exist for uuid '$uuid'");
            }
            $this->libvirtDom = $dom;
        }

        return $this->libvirtDom;
    }

    /**
     * @return Filesystem
     */
    protected function getFilesystem(): Filesystem
    {
        return $this->filesystem;
    }

    /**
     * @param int $comPortNumber
     * @param string $address
     */
    protected function internalAddSerialPort(int $comPortNumber, string $address)
    {
        $this->serialPorts[$comPortNumber] = $address;
    }

    /**
     * Throw exception if not expected connection type
     *
     * @param ConnectionType $expected
     * @param ConnectionType $actual
     */
    protected static function assertConnectionType(ConnectionType $expected, ConnectionType $actual)
    {
        if ($expected !== $actual) {
            throw new RuntimeException("Expected ConnectionType of '$expected', but found '$actual'");
        }
    }

    /**
     * @param int $timeout Wait up to $timeout seconds for the vm to shutdown
     * @return bool True if the virtual machine is shutdown, false if not
     */
    private function waitForShutdown(int $timeout): bool
    {
        $timeoutMs = $timeout * 1000;
        while (!$this->isShutdown() && $timeoutMs > 0) {
            $timeoutMs -= 50;
            $this->sleep->msleep(50);
        }

        return $this->isShutdown();
    }
}
