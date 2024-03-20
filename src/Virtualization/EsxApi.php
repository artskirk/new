<?php

namespace Datto\Virtualization;

use Datto\Connection\Libvirt\EsxHostType;
use Datto\Virtualization\Exceptions\EsxApiInitException;
use RuntimeException;
use Throwable;
use VirtualMachineTicket;
use Vmwarephp;

/**
 * Encapsulation of Vmware SOAP api
 * @see Vmwarephp
 * @psalm-suppress UndefinedClass
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxApi
{
    /** @var Vmwarephp\Vhost */
    private $vhost;

    /** @var HostDatastoreSystem part of remote Vmware API */
    private $datastoreSystem;

    /** @var HostStorageSystem part of remote Vmware API */
    private $storageSystem;

    /** @var HostSystem part of remote Vmware API */
    private $hostSystem;

    /** @var HostFirewallSystem part of remote Vmware API */
    private $firewallSystem;

    /** @var ServiceContent part of remote Vmware API */
    private $serviceContent;

    /** @var bool * */
    private $isInitialized = false;

    /** @var null|string */
    private $primaryHost;

    /** @var null|string */
    private $user;

    /** @var null|string */
    private $password;

    /** @var null|string */
    private $hostType;

    /** @var null|string */
    private $esxHost;

    /**
     * @param string|null $primaryHost
     * @param string|null $user
     * @param string|null $password
     * @param string|null $hostType
     * @param string|null $esxHost
     */
    public function __construct($primaryHost, $user, $password, $hostType, $esxHost)
    {
        $this->primaryHost = $primaryHost;
        $this->user = $user;
        $this->password = $password;
        $this->hostType = $hostType;
        $this->esxHost = $esxHost;
    }

    /**
     * Vhost api proxy
     *
     * @return Vmwarephp\Vhost
     */
    public function getVhost(): Vmwarephp\Vhost
    {
        $this->initialize();
        return $this->vhost;
    }

    /**
     * Datastore api proxy
     *
     * @psalm-suppress UndefinedClass
     * @return HostDatastoreSystem|ManagedObject
     */
    public function getDatastoreSystem()
    {
        $this->initialize();
        return $this->datastoreSystem;
    }

    /**
     * Storage api proxy
     *
     * @psalm-suppress UndefinedClass
     * @return HostStorageSystem|ManagedObject
     */
    public function getStorageSystem()
    {
        $this->initialize();
        return $this->storageSystem;
    }

    /**
     * Host api proxy
     *
     * @psalm-suppress UndefinedClass
     * @return HostSystem|ManagedObject
     */
    public function getHostSystem()
    {
        $this->initialize();
        return $this->hostSystem;
    }

    /**
     * Firewall api proxy
     *
     * @psalm-suppress UndefinedClass
     * @return HostFirewallSystem|ManagedObject
     */
    public function getFirewallSystem()
    {
        $this->initialize();
        return $this->firewallSystem;
    }

    /**
     * Service api proxy
     *
     * @psalm-suppress UndefinedClass
     * @return ServiceContent|ManagedObject
     */
    public function getServiceContent()
    {
        $this->initialize();
        return $this->serviceContent;
    }

    /**
     * Get a virtual machine from this connection by name
     *
     * @param string $vmName
     * @return Vmwarephp\Extensions\VirtualMachine
     */
    private function getVirtualMachine(string $vmName): Vmwarephp\Extensions\VirtualMachine
    {
        $this->initialize();

        $virtualMachine = $this->vhost->findManagedObjectByName(
            'VirtualMachine',
            $vmName,
            ['name']
        );

        if (empty($virtualMachine)) {
            throw new RuntimeException(sprintf('Could not lookup VM by name: %s', $vmName));
        }

        return $virtualMachine;
    }

    /**
     * Get a WMKS ticket to allow connecting to a specified VM
     *
     * @param string $vmName
     * @return VirtualMachineTicket
     */
    public function acquireTicket(string $vmName): VirtualMachineTicket
    {
        $vm = $this->getVirtualMachine($vmName);

        $ticket = $vm->AcquireTicket(
            ['ticketType' => 'webmks']
        );

        if ($ticket === false) {
            throw new RuntimeException(
                'Failed to acquire WebMKS Ticket - aborting'
            );
        }

        return $ticket;
    }

    /**
     * initialize the api interfaces
     */
    private function initialize()
    {
        if ($this->isInitialized) {
            $this->ensureSession();
        } else {
            $this->createVhostConnection();
            $this->isInitialized = true;
        }
    }

    /**
     * Makes sure we have active login session.
     *
     * The device can spawn long-running processes (e.g. screenshot
     * verifications) that can cause VMware API login sessions to expire. This
     * method checks for this and re-logins to make sure API calls can be made
     * during the lifetime of the instances of this class.
     */
    private function ensureSession(): void
    {
        $sessionManager = $this->vhost->getSessionManager();

        // if currentSession is null, we're no longer logged in
        if (!$sessionManager->currentSession) {
            $this->createVhostConnection();
        }
    }

    private function createVhostConnection(): void
    {
        try {
            $this->vhost = new Vmwarephp\Vhost(
                $this->primaryHost,
                $this->user,
                $this->password
            );

            if ($this->hostType != EsxHostType::STANDALONE) {
                $this->hostSystem = $this->vhost->findManagedObjectByName(
                    'HostSystem',
                    $this->esxHost,
                    []
                );
            } else {
                $ret = $this->vhost->findAllManagedObjects(
                    'HostSystem',
                    []
                );

                $this->hostSystem = $ret[0];
            }

            $this->datastoreSystem = $this->hostSystem->configManager->datastoreSystem;
            $this->storageSystem = $this->hostSystem->configManager->storageSystem;
            $this->firewallSystem = $this->hostSystem->configManager->firewallSystem;
            $this->serviceContent = $this->vhost->getServiceContent();
        } catch (Throwable $ex) {
            throw new EsxApiInitException($ex);
        }
    }
}
