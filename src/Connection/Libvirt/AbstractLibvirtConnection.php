<?php

namespace Datto\Connection\Libvirt;

use Datto\Connection\AbstractConnection;
use Datto\Connection\ConnectionType;
use Datto\Restore\Virtualization\ConsoleType;
use Datto\Utility\ByteUnit;
use Datto\Virtualization\Libvirt\Libvirt;
use Exception;

abstract class AbstractLibvirtConnection extends AbstractConnection
{
    protected $uri = '';

    /** @var Libvirt */
    private $libvirt;

    /**
     * @return bool
     */
    public function isLibvirt()
    {
        return true;
    }

    /**
     * Generates libvirt URI string based on the underlying data.
     */
    abstract protected function buildUri();

    /**
     * Returns URI string suitable to establish a working libvirt connection.
     *
     * @return string
     */
    public function getUri()
    {
        $this->buildUri();

        return $this->uri;
    }

    /**
     * Helper to determine if this is an ESX connection
     *
     * @return bool
     */
    public function isEsx()
    {
        return $this->connectionType === ConnectionType::LIBVIRT_ESX();
    }

    /**
     * Helper to determine if this is a Hyper-V connection
     *
     * @return bool
     */
    public function isHyperV()
    {
        return $this->connectionType === ConnectionType::LIBVIRT_HV();
    }

    /**
     * Helper to determine if this is a connection to a remote hypervisor
     *
     * @return bool
     */
    public function isRemote()
    {
        return $this->isEsx() || $this->isHyperV();
    }

    /**
     * Helper to determine if this is a KVM connection
     *
     * @return bool
     */
    public function isKvm()
    {
        return $this->connectionType === ConnectionType::LIBVIRT_KVM();
    }

    /**
     * Helper to determine if this is a connection to a device hosted hypervisor
     *
     * @return bool
     */
    public function isLocal()
    {
        return $this->isKvm();
    }

    /**
     * @return Libvirt
     */
    public function getLibvirt(): Libvirt
    {
        if (is_null($this->libvirt)) {
            $this->libvirt = new Libvirt($this->getType(), $this->getUri(), $this->getCredentials());
            if (!$this->libvirt->isConnected()) {
                throw new Exception($this->libvirt->getLastError());
            }
        }

        return $this->libvirt;
    }

    /**
     * Get available memory on the hypervisor in MiB
     *
     * @return int
     */
    public function getHostFreeMemoryMiB(): int
    {
        return intVal(ByteUnit::BYTE()->toMiB($this->getLibvirt()->hostGetNodeFreeMemory()));
    }

    /**
     * Get total memory on the hypervisor in MiB
     *
     * @return int
     */
    public function getHostTotalMemoryMiB(): int
    {
        return intVal(ByteUnit::KIB()->toMiB($this->getLibvirt()->hostGetNodeTotalMemory()));
    }

    /**
     * When offloading, returns the hostname running the hypervisor
     *
     * @return string|null
     */
    abstract public function getHost();

    /**
     * Whether or not the connection supports VNC connections.
     * @return bool
     */
    public function supportsVnc(): bool
    {
        return false;
    }

    /**
     * Whether or not the connection supports WMKS (Web Mouse/Keyboard/Screen) console connections
     * @return bool
     */
    public function supportsWmks(): bool
    {
        return false;
    }

    /**
     * Determine the remote console type to use for this connection
     * @return string|null
     */
    public function getRemoteConsoleType(): ?string
    {
        if ($this->supportsVnc()) {
            return ConsoleType::VNC;
        } elseif ($this->supportsWmks()) {
            return ConsoleType::WMKS;
        }
        return null;
    }

    /**
     * Get information about the remote console, including host and port if possible.
     *
     * Prefer calling this on the VM, as some remote information can only be retrieved
     * from an active virtualization.
     * @return AbstractRemoteConsoleInfo|null
     */
    public function getRemoteConsoleInfo(string $vmName): ?AbstractRemoteConsoleInfo
    {
        return null;
    }
}
