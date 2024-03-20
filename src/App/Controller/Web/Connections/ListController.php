<?php

namespace Datto\App\Controller\Web\Connections;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Connection\AbstractConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\HvConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Restore\RestoreService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;

/**
 * Handles requests for the Hypervisor Connections page
 *
 * @author Brian Grogan <bgrogan@datto.com>
 */
class ListController extends AbstractBaseController
{
    private ConnectionService $connections;
    private RestoreService $restoreService;
    private array $friendlyHostTypes = [
        'stand-alone' => 'Standalone Host',
        'vcenter-managed' => 'vCenter Managed Host',
        'cluster' => 'Cluster'
    ];
    private DeviceConfig $deviceConfig;

    public function __construct(
        NetworkService $networkService,
        DeviceConfig $deviceConfig,
        ConnectionService $connections,
        RestoreService $restoreService,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->connections = $connections;
        $this->deviceConfig = $deviceConfig;
        $this->restoreService = $restoreService;
    }

    /**
     * Controller action to render a table of all hypervisor connections
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_READ")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $availableConnections = $this->connections->getAll();

        $esxConnections = $this->buildEsxConnectionView(
            $availableConnections,
            ConnectionService::ESX_INFO_PATTERN
        );

        $hvConnections = $this->buildHvConnectionView(
            $availableConnections,
            ConnectionService::HV_INFO_PATTERN
        );

        $localConnection = $this->connections->getLocal();
        $isLocalPrimary = $this->connections->isLocalPrimary();
        $localConnectionView = $this->buildLocalConnectionView($localConnection, $isLocalPrimary);

        return $this->render(
            'Connections/List/index.html.twig',
            array(
                'esxConnections' => $esxConnections,
                'hvConnections' => $hvConnections,
                'localConnection' => $localConnectionView,
                'isVirtual' => $this->deviceConfig->has('isVirtual')
            )
        );
    }

    /**
     * Builds up the values to display for ESX hypervisor connections shown in the table of connections.
     *
     * @param array $connections
     * @param string $infoPath
     *
     * @return array
     */
    private function buildEsxConnectionView($connections = array(), $infoPath = '')
    {
        $connectionValues = array();

        foreach ($connections as $connection) {
            if ($connection instanceof EsxConnection) {
                $values = array();
                $values['name'] = $connection->getName();
                $values['server'] = $connection->getPrimaryHost();
                $values['type'] = $this->friendlyHostTypes[$connection->getHostType()];
                $values['offload'] = $connection->getOffloadMethod() === 'nfs' ? 'NFS' : 'iSCSI - ' . $connection->getIscsiHba();
                $values['datastore'] = $connection->getOffloadMethod() === 'nfs' ? 'n/a' : $connection->getDatastore();
                $values['systems'] = $connection->isUsedForBackup($infoPath);
                $values['checked'] = $connection->isPrimary() ? 'checked' : '';
                $values['restores'] = $this->restoreService->getActiveRestoresForConnection($connection->getName());

                $connectionValues[] = $values;
            }
        }

        return $connectionValues;
    }

    /**
     * Builds up the values to display for HyperV hypervisor connections shown in the table of connections.
     *
     * @param array $connections
     * @param string $infoPath
     *
     * @return array
     */
    private function buildHvConnectionView($connections = array(), $infoPath = '')
    {
        $connectionValues = array();

        foreach ($connections as $connection) {
            if ($connection instanceof HvConnection) {
                $values = array();
                $values['name'] = $connection->getName();
                $values['server'] = $connection->getHostname();
                $values['systems'] = $connection->isUsedForBackup($infoPath);
                $values['checked'] = $connection->isPrimary() ? 'checked' : '';
                $values['allowScreenshots'] = $connection->supportsScreenshots() ? '' : 'disabled';
                $values['restores'] = $this->restoreService->getActiveRestoresForConnection($connection->getName());

                $connectionValues[] = $values;
            }
        }

        return $connectionValues;
    }

    /**
     * Builds the values for local hypervisor connection, which are very simple and display very little information.
     *
     * @param AbstractConnection|null $connection
     * @param bool $isPrimary
     *
     * @return array
     */
    private function buildLocalConnectionView(AbstractConnection $connection = null, $isPrimary = false)
    {
        if ($connection) {
            return array(
                'name' => $connection->getName(),
                'checked' => $isPrimary ? 'checked' : ''
            );
        } else {
            return array();
        }
    }
}
