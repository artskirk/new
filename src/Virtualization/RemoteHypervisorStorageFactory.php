<?php

namespace Datto\Virtualization;

use Datto\AppKernel;
use Datto\Common\Resource\ProcessFactory;
use Datto\Connection\ConnectionInterface;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\HvConnection;
use Datto\Core\Network\DeviceAddress;
use Datto\Filesystem\TransparentMount;
use Datto\Filesystem\TransparentMountFactory;
use Datto\Iscsi\IscsiTarget;
use Datto\Nfs\NfsExportManager;
use Datto\Common\Utility\Filesystem;
use Datto\Util\RetryHandler;
use Datto\Winexe\Winexe;
use Datto\Winexe\WinexeApi;
use Datto\Log\DeviceLoggerInterface;
use RuntimeException;

/**
 * Create a RemoteHypervisorStorage instance from a connection
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class RemoteHypervisorStorageFactory
{
    private IscsiTarget $iscsiTarget;
    private DeviceAddress $deviceAddress;
    private NfsExportManager $nfsExportManager;
    private Filesystem $filesystem;
    private TransparentMount $transparentMount;
    private RetryHandler $retryHandler;

    public function __construct(
        IscsiTarget $iscsiTarget = null,
        DeviceAddress $deviceAddress = null,
        NfsExportManager $nfsExportManager = null,
        Filesystem $filesystem = null,
        TransparentMount $transparentMount = null,
        RetryHandler $retryHandler = null,
        TransparentMountFactory $transparentMountFactory = null
    ) {
        $this->iscsiTarget = $iscsiTarget ?? new IscsiTarget();
        $this->deviceAddress = $deviceAddress ??
            AppKernel::getBootedInstance()->getContainer()->get(DeviceAddress::class);
        $this->nfsExportManager = $nfsExportManager ?? new NfsExportManager();
        $this->filesystem = $filesystem ?? new Filesystem(new ProcessFactory());
        $this->retryHandler = $retryHandler ?? new RetryHandler();
        $transparentMountFactory = $transparentMountFactory ??
            AppKernel::getBootedInstance()->getContainer()->get(TransparentMountFactory::class);
        $this->transparentMount = $transparentMount ?? $transparentMountFactory->create();
    }

    /**
     * Create a remote storage instance for this connection
     * Union type must stay in psalm till PHP 8.0 +
     * @param AbstractLibvirtConnection|ConnectionInterface  $connection
     */
    public function create($connection, DeviceLoggerInterface $logger): RemoteHypervisorStorageInterface
    {
        if ($connection instanceof EsxConnection) {
            return new EsxRemoteStorage(
                $connection,
                $this->deviceAddress,
                $this->filesystem,
                $this->iscsiTarget,
                $this->nfsExportManager,
                $this->transparentMount,
                $logger
            );
        } elseif ($connection instanceof HvConnection) {
            return new HvRemoteStorage(
                $connection,
                $this->deviceAddress,
                $this->iscsiTarget,
                $this->initWinexeApi($connection),
                $this->retryHandler,
                $logger
            );
        } else {
            throw new RuntimeException("Unsupported connection type '{$connection->getType()}'");
        }
    }

    /**
     * Creates winexe API instance from Hyper-V connection info.
     *
     * @param HvConnection $connection
     *
     * @return WinexeApi
     *
     * @codeCoverageIgnore
     */
    private static function initWinexeApi(HvConnection $connection): WinexeApi
    {
        $winexe = new Winexe(
            $connection->getHostname(),
            $connection->getUser(),
            $connection->getPassword(),
            $connection->getDomain()
        );

        return new WinexeApi($winexe);
    }
}
