<?php

namespace Datto\Virtualization;

use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Virtualization\EsxNetworking;
use Datto\Service\Security\FirewallService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Virtual machine using ESX hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxVirtualMachine extends RemoteVirtualMachine
{
    private EsxNetworking $esxNetwork;
    private DeviceLoggerInterface $logger;

    public function __construct(
        string $name,
        string $uuid,
        string $storageDir,
        AbstractLibvirtConnection $connection,
        Filesystem $filesystem,
        Sleep $sleep,
        EsxNetworking $esxNetwork,
        DeviceLoggerInterface $logger,
        FirewallService $firewallService
    ) {
        $this->assertConnectionType(ConnectionType::LIBVIRT_ESX(), $connection->getType());
        parent::__construct($name, $uuid, $storageDir, $connection, $filesystem, $sleep, $logger, $firewallService);
        $this->esxNetwork = $esxNetwork;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function addSerialPort(int $comPortNumber = 1)
    {
        parent::addSerialPort($comPortNumber);
        $this->esxNetwork->enableSerialPortFirewallRuleset();
    }

    /**
     * @inheritdoc
     */
    public function start()
    {
        parent::start();

        $libvirt = $this->getConnection()->getLibvirt();
        $dom = $this->getLibvirtDom();
        /* Wait until the domain is actually seen as running
         * This is because on ESX, even when PowerOnVM_Task completes
         * there's some lag before it's actually reported as running
         * so we need to wait here until we have confirmation.
         * This usually takes 0-3 seconds so 90s timeout should be plenty
         * enough for this.
         */
        $timeout = 90;
        $secondsWaited = 0;
        while (!$libvirt->domainIsRunning($dom)) {
            if ($secondsWaited >= $timeout) {
                break;
            }
            $secondsWaited += 2;
            $this->sleep->sleep(2);
        }
    }

    /**
     * @inheritdoc
     */
    protected function powerDown(bool $cleanShutdown): bool
    {
        // don't power off migrated VMs
        if ($this->isEsxVmMigrated()) {
            return false;
        }

        return parent::powerDown($cleanShutdown);
    }

    /**
     * Determines if restore VM was migrated of off device storage.
     */
    public function isEsxVmMigrated(): bool
    {
        /** @var EsxConnection $connection */
        $connection = $this->getConnection();
        $dom = $this->getLibvirtDom();
        $libvirt = $this->getConnection()->getLibvirt();
        $vmName = $libvirt->domainGetName($dom);
        $datastore = $connection->getDatastore();
        $attachedDisks = $libvirt->domainGetAttachedDiskPaths($dom);

        $drivePath = $attachedDisks[0];
        $nfsName = str_replace('-restore', '-active', $vmName);
        $isOnOurIscsiDatastore = preg_match('/\[' . preg_quote($datastore, '/') . '\]/', $drivePath);
        $isOnOurNfsShare = preg_match('/\[' . preg_quote($nfsName, '/') . '\]/', $drivePath);
        $this->logger->debug('ESX0221 Determine VM storage', ['vmName' => $vmName,
            'datastore' => $datastore, 'drivePath' => $drivePath]);

        // VM storage seems to point at our share(s), so not migrated.
        if ($isOnOurIscsiDatastore || $isOnOurNfsShare) {
            return false;
        }

        return true;
    }
}
