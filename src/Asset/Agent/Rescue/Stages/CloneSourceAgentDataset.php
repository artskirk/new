<?php

namespace Datto\Asset\Agent\Rescue\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\Rescue\RescueAgentCreationContext;
use Datto\Dataset\DatasetFactory;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\Virtualization\VirtualizationRestoreTool;
use Datto\Common\Utility\Filesystem;
use Datto\ZFS\ZfsDataset;
use Datto\Log\DeviceLoggerInterface;

/**
 * A transactional Stage that handles the creation of the new rescue agent dataset.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class CloneSourceAgentDataset extends CreationStage
{
    private const STATUS_MESSAGE = 'cloneDataset';

    private AssetCloneManager $cloneManager;
    private Filesystem $filesystem;
    private VirtualizationRestoreTool $virtRestoreTool;
    private DatasetFactory $datasetFactory;
    private TempAccessService $tempAccessService;

    public function __construct(
        RescueAgentCreationContext $context,
        VirtualizationRestoreTool $virtRestoreTool,
        AssetCloneManager $cloneManager,
        Filesystem $filesystem,
        DeviceLoggerInterface $logger,
        DatasetFactory $datasetFactory,
        TempAccessService $tempAccessService
    ) {
        parent::__construct($logger, $context);

        $this->virtRestoreTool = $virtRestoreTool;
        $this->cloneManager = $cloneManager;
        $this->filesystem = $filesystem;
        $this->datasetFactory = $datasetFactory;
        $this->tempAccessService = $tempAccessService;
    }

    /**
     * Clone the source agent dataset to the new rescue agent dataset.
     */
    public function commit(): void
    {
        $rescueAgent = $this->context->getRescueAgent();

        $cloneSpec = CloneSpec::fromRescueAgent($rescueAgent);

        // decrypt both source and rescue agent keys
        $this->virtRestoreTool->decryptAgentKey(
            $this->context->getSourceAgent()->getKeyName(),
            $this->context->getEncryptionPassphrase()
        );
        if (!$this->tempAccessService->isCryptTempAccessEnabled($this->context->getSourceAgent()->getKeyName())) {
            $this->virtRestoreTool->decryptAgentKey(
                $this->context->getRescueAgent()->getKeyName(),
                $this->context->getEncryptionPassphrase()
            );
        }

        $this->cloneManager->createClone($cloneSpec);

        $dataset = $this->datasetFactory->createZfsDataset($cloneSpec->getTargetDatasetName());
        $rescueAgent->setDataset($dataset);

        $dataset->setAttribute(ZfsDataset::UUID_PROPERTY, $rescueAgent->getUuid());

        //  rescue agents will inherit sync=disabled from homePool/home
        //  but they should be sync=standard like other restore clones so that KVM can use synchronous writes
        $dataset->setAttribute(ZfsDataset::SYNC_PROPERTY, ZfsDataset::SYNC_VALUE_STANDARD);

        $newAgentInfoFile = Agent::KEYBASE . $this->context->getRescueAgent()->getKeyName() . '.agentInfo';
        if (!$this->filesystem->copy($newAgentInfoFile, $dataset->getMountPoint())) {
            throw new \Exception(
                'Failed to copy .agentInfo file for newly created rescue agent ' . $this->context->getRescueAgent()->getKeyName()
            );
        }

        if (!$this->context->getSourceAgent()->isSupportedOperatingSystem()) {
            $newDiskDrives = Agent::KEYBASE . $this->context->getRescueAgent()->getKeyName() . '.diskDrives';
            $newDiskDrivesExists = $this->filesystem->exists($newDiskDrives);
            if ($newDiskDrivesExists && !$this->filesystem->copy($newDiskDrives, $dataset->getMountPoint())) {
                throw new \Exception(
                    'Failed to copy .diskDrives file for newly created rescue agent ' . $this->context->getRescueAgent()->getKeyName()
                );
            }
        }

        $this->context->setCloneSpec($cloneSpec);
    }

    /**
     * In the case of a rollback, we need to destroy the clone.
     */
    public function rollback(): void
    {
        try {
            $rescueAgent = $this->context->getRescueAgent();
            $cloneSpec = CloneSpec::fromRescueAgent($rescueAgent);
            $recursiveDestroy = true;
            $this->cloneManager->destroyClone($cloneSpec, $recursiveDestroy);
        } catch (\Exception $e) {
            $path = isset($cloneSpec) ? $cloneSpec->getTargetDatasetName() : '';
            $this->logger->error('RSC1001 Could not destroy ZFS clone', ['dataset' => $path]);
        }
    }

    public function getStatusMessage(): string
    {
        return self::STATUS_MESSAGE;
    }
}
