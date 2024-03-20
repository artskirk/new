<?php

namespace Datto\Connection\Libvirt;

use Datto\Connection\ConnectionType;
use Datto\Restore\Virtualization\ConsoleType;
use Datto\Virtualization\EsxApi;

/**
 * Represents a VMware ESX connection.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class EsxConnection extends AbstractAuthConnection
{
    const OFFLOAD_ISCSI = 'iscsi';
    const OFFLOAD_NFS = 'nfs';

    /** @var EsxApi */
    private $esxApi;

    /**
     * Constructor
     *
     * @param string $name
     *  The name of the connection.
     */
    public function __construct($name)
    {
        parent::__construct(ConnectionType::LIBVIRT_ESX(), $name);
    }

    /**
     * {@inheritdoc}
     */
    protected function buildUri()
    {
        if (false === $this->isValid()) {
            return '';
        }

        $vcenter = $this->getVCenterHost();

        if (!empty($vcenter)) {
            if ($this->getCluster()) {
                $this->uri = sprintf(
                    'vpx://%s/%s/%s/%s?no_verify=1&auto_answer=1',
                    $vcenter,
                    rawurlencode($this->getDataCenterPath()),
                    rawurlencode($this->getClusterPath()),
                    $this->getEsxHost()
                );
            } else {
                $this->uri = sprintf(
                    'vpx://%s/%s/%s?no_verify=1&auto_answer=1',
                    $vcenter,
                    rawurlencode($this->getDataCenterPath()),
                    $this->getEsxHostPath()
                );
            }
        } else {
            $this->uri = sprintf(
                'esx://%s?no_verify=1&auto_answer=1',
                $this->getEsxHost()
            );
        }
    }

    /**
     * Check if this connection is to a standalone ESX host
     *
     * @return bool
     */
    public function isStandalone()
    {
        return $this->getHostType() === EsxHostType::STANDALONE;
    }

    /**
     * Get the offload method to use.
     *
     * @return string
     */
    public function getOffloadMethod()
    {
        $method = $this->getKey('offloadMethod');

        if ($method === null) {
            $method = self::OFFLOAD_NFS;
        }

        return $method;
    }

    /**
     * Set the offload method to use.
     *
     * @param string $offloadMethod
     */
    public function setOffloadMethod($offloadMethod)
    {
        $this->setKey('offloadMethod', $offloadMethod);
    }

    /**
     * Set the Esx Host Version.
     *
     * @param string $esxHostVersion
     */
    public function setEsxHostVersion($esxHostVersion)
    {
        $this->setKey('esxHostVersion', $esxHostVersion);
    }

    /**
     * Get the Esx Host Version.
     *
     * @return string|null
     */
    public function getEsxHostVersion()
    {
        return $this->getKey('esxHostVersion');
    }

    /**
     * Set the Esx Host License Name.
     *
     * @param string $esxHostLicenseProductName
     */
    public function setEsxHostLicenseProductName(string $esxHostLicenseProductName): void
    {
        $this->setKey('esxHostLicenseProductName', $esxHostLicenseProductName);
    }

    /**
     * Get the Esx Host License Name.
     *
     * @return string|null
     */
    public function getEsxHostLicenseProductName(): ?string
    {
        return $this->getKey('esxHostLicenseProductName');
    }

    /**
     * Set the Vcenter Version.
     *
     * @param string|null $vcenterHostVersion
     */
    public function setVcenterHostVersion(?string $vcenterHostVersion): void
    {
        $this->setKey('vcenterHostVersion', $vcenterHostVersion);
    }

    /**
     * Get the Esx Vcenter Version.
     *
     * @return string|null
     */
    public function getVcenterHostVersion(): ?string
    {
        return $this->getKey('vcenterHostVersion');
    }

    /**
     * Set the Vcenter License Name.
     *
     * @param string|null $vcenterHostLicenseProductName
     */
    public function setVcenterHostLicenseProductName(?string $vcenterHostLicenseProductName): void
    {
        $this->setKey('vcenterHostLicenseProductName', $vcenterHostLicenseProductName);
    }

    /**
     * Get the Vcenter License Name.
     *
     * @return string|null
     */
    public function getVcenterHostLicenseProductName(): ?string
    {
        return $this->getKey('vcenterHostLicenseProductName');
    }

    /**
     * Get datastore name.
     *
     * Required to create VMs, optional for just connecting.
     *
     * @return string|null
     */
    public function getDatastore(): ?string
    {
        return $this->getKey('datastore');
    }

    /**
     * Set datastore name.
     *
     * Required to create VMs, optional for just connecting.
     *
     * @param string $datastore
     */
    public function setDatastore($datastore): void
    {
        $this->setKey('datastore', $datastore);
    }

    /**
     * Get ESX host.
     *
     * Always required.
     *
     * @return string
     */
    public function getEsxHost()
    {
        return basename($this->getKey('esxHost') ?? '');
    }

    /**
     * Get full path to ESX host
     *
     * @return null|string
     */
    public function getEsxHostPath(): ?string
    {
        return $this->getKey('esxHost');
    }

    /**
     * Set ESX host.
     *
     * Always required.
     *
     * @param string $esxHost
     */
    public function setEsxHostPath($esxHost): void
    {
        $this->setKey('esxHost', $esxHost);
    }

    /**
     * Get vCenter host.
     *
     * @return string|null
     */
    public function getVCenterHost(): ?string
    {
        return $this->getKey('vCenterHost');
    }

    public function setVCenterHost(?string $vCenterHost): void
    {
        $this->setKey('vCenterHost', $vCenterHost);
    }

    /**
     * Get data canter name.
     *
     * Required for vCenter connections.
     *
     * @return string|null
     */
    public function getDataCenter()
    {
        return basename($this->getKey('dataCenter'));
    }

    /**
     * Get full path to DataCenter
     *
     * @return null|string
     */
    public function getDataCenterPath()
    {
        return $this->getKey('dataCenter');
    }

    /**
     * Set data center name.
     *
     * Requered for vCenter connections.
     *
     * @param string $dataCenter
     */
    public function setDatacenterPath($dataCenter)
    {
        $this->setKey('dataCenter', $dataCenter);
    }

    /**
     * Get cluster name.
     *
     * Required for vCenter connections.
     *
     * @return string|null
     */
    public function getCluster()
    {
        return basename($this->getKey('cluster'));
    }

    /**
     * Get full path to cluster
     *
     * @return null|string
     */
    public function getClusterPath()
    {
        return $this->getKey('cluster');
    }

    /**
     * Set cluster name.
     *
     * Required for vCenter connections.
     *
     * @param string $cluster
     */
    public function setClusterPath($cluster)
    {
        $this->setKey('cluster', $cluster);
    }

    /**
     * Set the cluster's reference ID.
     *
     * @param string $clusterId
     */
    public function setClusterId($clusterId)
    {
        $this->setKey('clusterId', $clusterId);
    }

    /**
     * Get the cluster's reference ID.
     *
     * @return string|null
     */
    public function getClusterId()
    {
        return $this->getKey('clusterId');
    }

    /**
     * Set the host's reference ID.
     *
     * @param $hostId
     */
    public function setHostId($hostId)
    {
        $this->setKey('hostId', $hostId);
    }

    /**
     * Get the host's reference ID.
     *
     * @return string|null
     */
    public function getHostId()
    {
        return $this->getKey('hostId');
    }

    /**
     * Get iSCSI host bus adapter name.
     *
     * Required to create VMs, optional for just connecting.
     *
     * @return string|null
     */
    public function getIscsiHba()
    {
        return $this->getKey('iScsiHba');
    }

    /**
     * Set iSCSI host bus adapter name.
     *
     * Required to create VMs, optional for just connecting.
     *
     * @param string $iScsiHba
     */
    public function setIscsiHba($iScsiHba)
    {
        $this->setKey('iScsiHba', $iScsiHba);
    }

    /**
     * Gets the host type.
     *
     * As in stand-alone ESX host, a cluster etc.
     *
     * @return string|null
     */
    public function getHostType()
    {
        return $this->getKey('hostType');
    }

    /**
     * Sets the host type.
     *
     * As in stand-alone ESX host, a cluster etc.
     *
     * @param string $type
     */
    public function setHostType($type)
    {
        $this->setKey('hostType', $type);

        return $this;
    }

    /**
     * Returns the virtualisation host prioritising the vCenter host over ESX.
     * @return string|null
     */
    public function getPrimaryHost()
    {
        $ret = $this->getVCenterHost();
        if (empty($ret)) {
            $ret = $this->getEsxHost();
        }
        return $ret;
    }

    /**
     * {@inheritdoc}
     * @return string|null
     */
    public function getHost()
    {
        return $this->getPrimaryHost();
    }

    /**
     * Checks wheter a minimum data is present to connect to ESX/vCenter host.
     *
     * Does not imply that VM creation will succeed as those also require
     * proper datastore and iSCSI HBA names etc. However, in practice this
     * info will usually be present as well as UI enforces specifying those.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        // all ESX/vCenter connections must have some credentials.
        if (!$this->getCredentials()) {
            return false;
        }

        // if it doesn't have a type, fail.
        if (!$this->getHostType()) {
            return false;
        }

        $type = $this->getHostType();
        if (!in_array(
            $type,
            [
                EsxHostType::STANDALONE,
                EsxHostType::VCENTER,
                EsxHostType::CLUSTER,
            ]
        )) {
            return false;
        }
        if (empty($this->getEsxHost())) {
            return false;
        }
        // checks for vCenter connections.
        if (in_array(
            $type,
            array(
                EsxHostType::CLUSTER,
                EsxHostType::VCENTER
            )
        )) {
            if (!$this->getVCenterHost()) {
                return false;
            }

            if (!$this->getDataCenter()) {
                return false;
            }

            if ($type == EsxHostType::CLUSTER
                && !$this->getCluster()) {
                return false;
            }
        }

        // offload mechanism, simply nfs, or iscsi with datastore and iscsiHba set
        $offloadMethod = $this->getOffloadMethod();
        $datastore = $this->getDatastore();
        $iscsiHba = $this->getIscsiHba();
        if ($offloadMethod === 'nfs') {
            return true;
        } elseif ($offloadMethod === 'iscsi') {
            if (empty($datastore) || empty($iscsiHba)) {
                return false;
            }
        } else {
            return false;
        }

        return true;
    }

    public function supportsVnc(): bool
    {
        // As long as the ESX offload host version is below 7.0, it supports VNC
        return version_compare($this->getEsxHostVersion(), '7.0.0', '<');
    }

    public function supportsWmks(): bool
    {
        // WebMKS is supported on ESX offload host versions >= 6.5. Technically, it's supported on older
        // versions as well, but 5.5 and 6.0 use a different mechanism for acquiring tickets that we don't support
        return version_compare($this->getEsxHostVersion(), '6.5.0', '>=');
    }

    public function getRemoteConsoleInfo(string $vmName): ?AbstractRemoteConsoleInfo
    {
        $type = $this->getRemoteConsoleType();

        if ($type === ConsoleType::WMKS) {
            $ticket = $this->getEsxApi()->acquireTicket($vmName);

            // If the ticket has a host, use it. Otherwise we can use the host we're connecting to.
            // This most often happens with standalone hosts.
            $host = $ticket->host ?? $this->getEsxHost();

            $output = new RemoteWmks(
                $host,
                $ticket->port,
                $ticket->ticket,
                $ticket->sslThumbprint,
                $ticket->cfgFile
            );
        } elseif ($type === ConsoleType::VNC) {
            $output = new RemoteVnc(
                $this->getEsxHost(),
                null
            );
        } else {
            $output = null;
        }

        return $output;
    }

    /**
     * The ESX api proxy
     */
    public function getEsxApi(): EsxApi
    {
        if (is_null($this->esxApi)) {
            $this->esxApi = new EsxApi(
                $this->getPrimaryHost(),
                $this->getUser(),
                $this->getPassword(),
                $this->getHostType(),
                $this->getEsxHost()
            );
        }

        return $this->esxApi;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        // Translate our connectionParams array to match what our setCorrectionParams method (formatted for UI) expects
        $connectionParams = $array['connectionParams'];
        $array['connectionParams']['datacenter'] = $connectionParams['dataCenter'];
        $array['connectionParams']['esxHostId'] = $connectionParams['hostId'];
        $isStandAlone = $connectionParams['hostType'] === 'stand-alone';
        $array['connectionParams']['server'] =
            $isStandAlone ? $connectionParams['esxHost'] : $connectionParams['vCenterHost'];

        return $array;
    }
}
