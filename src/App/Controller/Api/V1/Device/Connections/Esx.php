<?php

namespace Datto\App\Controller\Api\V1\Device\Connections;

use Datto\Connection\Service\EsxConnectionService;
use Datto\Log\SanitizedException;
use Datto\Utility\Security\SecretString;
use Throwable;

/**
 * This class contains the API endpoints for managing Esx hypervisor connections.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class Esx
{
    /** @var EsxConnectionService */
    protected $esxConnectionService;

    public function __construct(
        EsxConnectionService $esxConnectionService
    ) {
        $this->esxConnectionService = $esxConnectionService;
    }

    /**
     * Get the API type of the ESX server.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_READ")
     * @param string $server
     * @param string $username
     * @param string $password
     * @return string 'VirtualCenter' (vcenter) or 'HostAgent' (stand-alone host)
     */
    public function getApiType($server, $username, $password)
    {
        try {
            $password = new SecretString($password);
            return $this->esxConnectionService->getApiType($server, $username, $password);
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * Get the list of available datacenters.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_READ")
     * @param string $server
     * @param string $username
     * @param string $password
     * @return array[]
     */
    public function getDatacenters($server, $username, $password)
    {
        try {
            $password = new SecretString($password);
            $datacenters = $this->esxConnectionService->getDatacenters($server, $username, $password);
            $datacenterParameters = array();

            foreach ($datacenters as $datacenter) {
                $datacenterParameters[] = [
                    'name' => $datacenter->getName(),
                    'id' => $datacenter->getReferenceId()
                ];
            }
            return $datacenterParameters;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * Get the list of clusters associated with the given datacenter
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_READ")
     * @param string $server
     * @param string $username
     * @param string $password
     * @param string $datacenterId
     * @return array[]
     */
    public function getDatacenterClusters($server, $username, $password, $datacenterId)
    {
        try {
            $password = new SecretString($password);
            $clusters = $this->esxConnectionService
                ->getDatacenterClusters($server, $username, $password, $datacenterId);

            $clusterParameters = [];

            foreach ($clusters as $cluster) {
                $clusterParameters[] = [
                    'name' => $cluster->getName(),
                    'id' => $cluster->getReferenceId()
                ];
            }
            return $clusterParameters;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * Get the list of hosts available on a given vCenter host.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_READ")
     * @param string $server
     * @param string $username
     * @param string $password
     * @return array
     */
    public function getHosts($server, $username, $password)
    {
        try {
            $params = [
                'server' => $server,
                'username' => $username,
                'password' => $password
            ];
            return $this->esxConnectionService->getHypervisorOptions(EsxConnectionService::LIST_HOSTS, $params);
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * Get the list of hosts available on a given cluster.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_READ")
     * @param string $server
     * @param string $username
     * @param string $password
     * @param string $clusterId
     * @return array[]
     */
    public function getClusterHosts($server, $username, $password, $clusterId)
    {
        try {
            $password = new SecretString($password);
            $hosts = $this->esxConnectionService->getClusterHosts($server, $username, $password, $clusterId);
            $hostParameters = array();
            foreach ($hosts as $host) {
                $hostParameters[] = array(
                    'name' => $host->name,
                    'id' => $host->getReferenceId()
                );
            }
            return $hostParameters;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * Get list of host options
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_READ")
     * @param string $server
     * @param string $username
     * @param string $password
     * @param string $hostId
     * @param string $hostType
     * @return array
     */
    public function getOffloadMethods($server, $username, $password, $hostId, $hostType)
    {
        try {
            $params = array(
                'server' => $server,
                'username' => $username,
                'password' => $password,
                'hostId' => $hostId,
                'hostType' => $hostType
            );
            return $this->esxConnectionService->getHypervisorOptions(EsxConnectionService::LIST_HOST_OPTIONS, $params);
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }
}
