<?php

namespace Datto\App\Controller\Web\Connections;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\HvConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;

class AddController extends AbstractBaseController
{
    private ConnectionService $connectionService;

    public function __construct(
        NetworkService $networkService,
        ConnectionService $connectionService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->connectionService = $connectionService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_CREATE")
     *
     * @param string $onCloseRoute defaults to 'connections' route for Hypervisor wizard
     * @param string $connectionName name of the connection to edit
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($onCloseRoute, $connectionName = "")
    {
        $closeUrl = $this->generateUrl($onCloseRoute);
        $successUrl = $this->generateUrl('connections');

        return $this->render(
            'Connections/Add/index.html.twig',
            array(
                'hypervisors' => $this->getHypervisors(),
                'urls' => array(
                    'closeUrl' => $closeUrl,
                    'successUrl' => $successUrl
                ),
                'connection' => $this->getConnectionData($connectionName)
            )
        );
    }

    /**
     * Gets the hypervisors enabled on the device
     *
     * @return array hypervisor names
     */
    private function getHypervisors()
    {
        $hypervisors[] = array(
            'id' => 'esx',
            'name' => 'ESX'
        );
        $hypervisors[] = array(
            'id' => 'hv',
            'name' => 'Hyper-V'
        );
        return $hypervisors;
    }

    /**
     * @param string $connectionName
     * @return array
     */
    private function getConnectionData(string $connectionName)
    {
        $values = array();

        if (empty($connectionName)) {
            return $values;
        }

        $connection = $this->connectionService->get($connectionName);

        $values['name'] = $connection->getName();
        if ($connection instanceof EsxConnection) {
            $values['server'] = $connection->getPrimaryHost();
            $values['hypervisorType'] = 'esx';
            $values['dataCenter'] = $connection->getDataCenter();
            $values['cluster'] = $connection->getCluster();
            $values['host'] = $connection->getEsxHost();
            $values['offload'] = $connection->getOffloadMethod();
            $values['iscsiHba'] = $connection->getIscsiHba();
            $values['datastore'] = $connection->getDatastore();
            return $values;
        } elseif ($connection instanceof HvConnection) {
            $values['server'] = $connection->getHostname();
            $values['hypervisorType'] = 'hv';
            $values['http'] = $connection->isHttp();
        }
        return $values;
    }
}
