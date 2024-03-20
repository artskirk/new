<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Backup\AgentSnapshotRepository;
use Datto\Cloud\JsonRpcClient;
use Datto\Config\DeviceConfig;
use Datto\Common\Utility\Filesystem;
use Throwable;

/**
 * This backup stage requests azure vm metadata from device web.
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class UpdateAzureVmMetadata extends BackupStage
{
    const URL_REQUEST_FORMAT = 'v1/device/asset/backup/retrieveCloudAgentMetadata';

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var JsonRpcClient */
    private $jsonRpcClient;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        DeviceConfig $deviceConfig,
        JsonRpcClient $jsonRpcClient,
        Filesystem $filesystem
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->jsonRpcClient = $jsonRpcClient;
        $this->filesystem = $filesystem;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $assetKey = $this->context->getAsset()->getKeyName();

        $args = [
            'assetKey' => $assetKey
        ];

        try {
            $this->context->getLogger()->info('BAK8081 Retrieving Azure VM Metadata');
            $metadataResponse = $this->jsonRpcClient->queryWithId(self::URL_REQUEST_FORMAT, $args);

            $filePath = sprintf(
                AgentSnapshotRepository::KEY_AZURE_VM_METADATA_TEMPLATE,
                $this->context->getAsset()->getDataset()->getAttribute('mountpoint')
            );
            $this->filesystem->putAtomic($filePath, json_encode($metadataResponse, JSON_UNESCAPED_SLASHES));
            $this->context->getLogger()->debug(
                'BAK8082 Azure VM Metadata Retrieved',
                [
                    'AzureVmMetadata' => $metadataResponse,
                    'asset' => $assetKey
                ]
            );
        } catch (Throwable $t) {
            // Log but do not fail backup for metadata update problem
            $this->context->getLogger()->warning('BAK8080 Failed to update Azure VM Metadata');
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }
}
