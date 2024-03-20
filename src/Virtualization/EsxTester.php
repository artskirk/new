<?php

namespace Datto\Virtualization;

use Datto\Config\DeviceConfig;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Log\LoggerAwareTrait;
use Datto\Config\Virtualization\VirtualDisks;
use Datto\Config\Virtualization\VirtualDisk;
use Datto\Log\LoggerFactory;
use Datto\Virtualization\Exceptions\EsxApiInitException;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Vmwarephp\Vhost;

/**
 * Tests whether it is possible to share VM disk images over iSCSI to ESX host
 * given current configuration.
 * @psalm-suppress DeprecatedClass
 */
class EsxTester implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    private string $cloneDir = '/tmp/esx-test';
    private EsxConnection $connection;
    private RemoteHypervisorStorageFactory $storageFactory;
    private DeviceConfig $deviceConfig;
    // Must be to archive Legacy code working. Remove on full refactor
    private LoggerFactory $loggerFactory;

    public function __construct(
        EsxConnection $connection,
        RemoteHypervisorStorageFactory $storageFactory = null,
        LoggerFactory $loggerFactory = null,
        DeviceConfig $deviceConfig = null
    ) {
        $this->connection = $connection;
        $this->storageFactory = $storageFactory ?: new RemoteHypervisorStorageFactory();
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->loggerFactory = $loggerFactory ?: new LoggerFactory();
    }

    /**
     * Tests whether ESX connection is possible.
     *  True on success, false on fail - unreachable host or whatever.
     * @psalm-suppress UndefinedInterfaceMethod, PossiblyNullArgument
     */
    public function testConnection(): bool
    {
        try {
            $vhost = new Vhost(
                $this->connection->getPrimaryHost(),
                $this->connection->getUser(),
                $this->connection->getPassword()
            );

            $vhost->connect();
        } catch (Exception $ex) {
            if (strpos($ex->getMessage(), 'InvalidLogin') !== false) {
                throw new EsxApiInitException($ex);
            }

            return false;
        }

        return true;
    }

    /**
     * Tests whether it is possible to properly share VM images via iSCSI to
     * ESX host.
     */
    public function testIScsiOffload(): bool
    {
        $suffix = $this->deviceConfig->get('deviceID', 'vsiris');
        $this->cloneDir .= '-' . $suffix;

        $disks = self::createFakeVmdk();
        $remoteStorage = $this->storageFactory->create($this->connection, $this->loggerFactory->getDevice());

        $vmName = "esx-agent-test-$suffix";

        try {
            $remoteStorage->offload($vmName, $this->cloneDir, false, $disks);
        } finally {
            $remoteStorage->tearDown($vmName, $this->cloneDir, false);
        }
        return true;
    }

    /**
     * Creates and empty 10MiB VMDK and RAW image used to test sharing VM images
     * over iSCSI to ESX host.
     */
    private function createFakeVmdk(): VirtualDisks
    {
        $tenMib = 10 * 1024 * 1024;
        $rawName = 'empty-test-image.datto';
        $vmdkName = 'empty-test-image.vmdk';

        $sectors = $tenMib / 512;
        $cid = sprintf("%08x", mt_rand(0, 0xfffffffe));
        $uuid = md5(microtime() . $cid);
        $uuid = sprintf(
            "%s-%s-%s-%s-%s",
            substr($uuid, 0, 8),
            substr($uuid, 8, 4),
            substr($uuid, 12, 4),
            substr($uuid, 16, 4),
            substr($uuid, 20, 12)
        );
        $vmdk_contents = <<<EOF
# Disk DescriptorFile
version=1
CID=$cid
parentCID=ffffffff
createType="vmfs"

# Extent description
RW $sectors VMFS $rawName

# The Disk Data Base
#DDB

ddb.virtualHWVersion = "4"

ddb.geometry.cylinders="1"
ddb.geometry.heads="255"
ddb.geometry.sectors="63"
ddb.uuid.image="$uuid"
ddb.uuid.parent="00000000-0000-0000-0000-000000000000"
ddb.uuid.modification="00000000-0000-0000-0000-000000000000"
ddb.uuid.parentmodification="00000000-0000-0000-0000-000000000000"

EOF;
        @mkdir($this->cloneDir);
        $rawPath = $this->cloneDir . '/' . $rawName;

        $handle = fopen($rawPath, 'w');
        ftruncate($handle, $tenMib);
        fclose($handle);

        file_put_contents($this->cloneDir . '/' . $vmdkName, $vmdk_contents);

        $fakeDisk = new VirtualDisk(
            $rawName,
            $vmdkName,
            $this->cloneDir,
            false
        );

        $ret = new VirtualDisks();
        $ret->append($fakeDisk);

        return $ret;
    }
    
    public function __destruct()
    {
        array_map('unlink', glob($this->cloneDir . '/*'));
    }
}
