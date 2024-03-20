<?php

namespace Datto\App\Controller\Web\Restore;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Common\Resource\Filesystem;
use Datto\Config\AgentShmConfigFactory;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Common\Resource\PosixHelper;
use Datto\Restore\EsxUpload\EsxUploadManager;
use Datto\Restore\EsxUpload\EsxUploadStatus;
use Datto\Restore\RestoreService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the ESX upload page.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class EsxUploadController extends AbstractBaseController
{
    private AgentService $agentService;
    private EsxConnectionService $connectionService;
    private RestoreService $restoreService;
    private AgentShmConfigFactory $agentShmConfigFactory;
    private PosixHelper $posixHelper;
    private TempAccessService $tempAccessService;

    public function __construct(
        NetworkService $networkService,
        AgentService $agentService,
        EsxConnectionService $connectionService,
        RestoreService $restoreService,
        AgentShmConfigFactory $agentShmConfigFactory,
        PosixHelper $posixHelper,
        TempAccessService $tempAccessService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->agentService = $agentService;
        $this->connectionService = $connectionService;
        $this->restoreService = $restoreService;
        $this->agentShmConfigFactory = $agentShmConfigFactory;
        $this->posixHelper = $posixHelper;
        $this->tempAccessService = $tempAccessService;
    }

    /**
     * Render the hypervisor selection page for ESX upload.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_HYPERVISOR_UPLOAD")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_HYPERVISOR_UPLOAD_READ")
     *
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     *
     * @param string $assetKey
     * @param int $snapshot
     * @return RedirectResponse|Response
     */
    public function selectConnectionAction(string $assetKey, int $snapshot)
    {
        $this->validateAgentSnapshot($assetKey, $snapshot);

        $parameters = $this->getActiveEsxUploadParameters($assetKey, $snapshot);
        if ($parameters) {
            return $this->redirectToRoute('esx_upload', $parameters);
        }

        $connections = $this->getConnectionParameters();

        // If there is only one hypervisor connection redirect to the upload page using that connection's
        // name as the parameter
        if (count($connections) === 1) {
            $parameters = array(
                'assetKey' => $assetKey,
                'snapshot' => $snapshot,
                'connectionName' => $connections[0]['name']
            );
            return $this->redirectToRoute('esx_upload', $parameters);
        }

        return $this->render(
            'Restore/EsxUpload/hypervisors.html.twig',
            array(
                'connections' => $connections,
                'assetKey' => $assetKey,
                'snapshot' => $snapshot
            )
        );
    }

    /**
     * Render the upload dialog page for ESX upload.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_HYPERVISOR_UPLOAD")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_HYPERVISOR_UPLOAD_READ")
     *
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKey" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "connectionName" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param string $connectionName
     * @return Response
     */
    public function uploadAction(string $assetKey, int $snapshot, string $connectionName)
    {
        $this->validateAgentSnapshot($assetKey, $snapshot);

        $agent = $this->agentService->get($assetKey);
        $directoryPrefix = $agent instanceof AgentlessSystem ?
            $agent->getDisplayName() : $agent->getFullyQualifiedDomainName();

        $shmConfig = $this->agentShmConfigFactory->create($assetKey);
        $esxUploadStatus = new EsxUploadStatus($snapshot);
        $shmConfig->loadRecord($esxUploadStatus);
        $isRunning = $esxUploadStatus->getPid() > 0 && $this->posixHelper->isProcessRunning($esxUploadStatus->getPid());
        $needsEncryptionPrompt = $agent->getEncryption()->isEnabled() && !$this->tempAccessService->isCryptTempAccessEnabled($assetKey);

        return $this->render(
            'Restore/EsxUpload/upload.html.twig',
            array(
                'datastore' => $this->getDatastoreParameters($connectionName),
                'assetKey' => $assetKey,
                'displayName' => $agent->getDisplayName(),
                'snapshot' => $snapshot,
                'connectionName' => $connectionName,
                'isRunning' => $isRunning,
                'directoryPrefix' => $directoryPrefix,
                'needsEncryptionPrompt' => $needsEncryptionPrompt
            )
        );
    }

    /**
     * @return array
     */
    private function getConnectionParameters()
    {
        $connections = array();

        foreach ($this->connectionService->getAll() as $connection) {
            $connections[] = array(
                'name' => $connection->getName(),
                'server' => $connection->getPrimaryHost(),
                'type' => $connection->getHostType(),
                'offload' => $connection->getOffloadMethod() === 'nfs' ?
                    'NFS' :
                    'iSCSI - ' . $connection->getIscsiHba(),
                'datastore' => $connection->getDatastore(),
                'useForScreenshots' => $connection->isPrimary()
            );
        }
        return $connections;
    }

    /**
     * @param string $connectionName
     * @return array
     */
    private function getDatastoreParameters($connectionName)
    {
        $connection = $this->connectionService->get($connectionName);
        if (!$connection) {
            throw new Exception('Failed to retrieve the ESX connection');
        }
        $datastores = $this->connectionService->getDatastores($connection);

        $datastoreNames = array();
        foreach ($datastores as $datastore) {
            $datastoreNames[] = $datastore->getName();
        }
        $defaultDatastore = $connection->getDatastore();

        return array (
            'names' => $datastoreNames,
            'default' => $defaultDatastore
        );
    }

    /**
     * @param string $assetKey
     * @param int $snapshot
     */
    private function validateAgentSnapshot($assetKey, $snapshot): void
    {
        $agent = $this->agentService->get($assetKey);
        $recoveryPoints = $agent->getLocal()->getRecoveryPoints();
        if (!$recoveryPoints->exists($snapshot)) {
            throw new Exception("Snapshot $snapshot does not exist for agent $assetKey");
        }
    }

    private function getActiveEsxUploadParameters($assetKey, $snapshot): ?array
    {
        $assetRestores = $this->restoreService->getForAsset($assetKey);
        foreach ($assetRestores as $restore) {
            if ($restore->getSuffix() === EsxUploadManager::RESTORE_SUFFIX && $snapshot === $restore->getPoint()) {
                $options = $restore->getOptions();
                $parameters = array(
                    'assetKey' => $assetKey,
                    'snapshot' => $restore->getPoint(),
                    'connectionName' => $options['connectionName']
                );
                return $parameters;
            }
        }
        return null;
    }
}
