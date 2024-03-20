<?php

namespace Datto\Asset\Agent;

use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Block\BlockDeviceManager;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * This class is used to create DattoImage objects.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Stephen Allan <sallan@datto.com>
 */
class DattoImageFactory
{
    /** @var DattoImage[][]
     * array {
     *   '/images/directory/1' -> array {
     *     'guid1' -> DattoImage,
     *     'guid2' -> DattoImage
     *   },
     *   '/images/directory/2' -> array {
     *     'guid3' -> DattoImage
     *   },
     *   ...
     * }
     */
    private $directoryToImagesMapping = [];

    /** @var AgentSnapshotService */
    private $agentSnapshotService;

    /** @var Filesystem */
    private $filesystem;

    /** @var BlockDeviceManager */
    private $blockDeviceManager;

    /** @var EncryptionService */
    private $encryptionService;

    /**
     * @param AgentSnapshotService $agentSnapshotService
     * @param Filesystem $filesystem
     * @param BlockDeviceManager $blockDeviceManager
     * @param EncryptionService $encryptionService
     */
    public function __construct(
        AgentSnapshotService $agentSnapshotService,
        Filesystem $filesystem,
        BlockDeviceManager $blockDeviceManager,
        EncryptionService $encryptionService
    ) {
        $this->agentSnapshotService = $agentSnapshotService;
        $this->filesystem = $filesystem;
        $this->blockDeviceManager = $blockDeviceManager;
        $this->encryptionService = $encryptionService;
    }

    /**
     * Create one DattoImage object for each volume in the Agent's live dataset.
     *
     * @param Agent $agent
     * @return DattoImage[]
     */
    public function createImagesForLiveDataset(Agent $agent): array
    {
        $imagesDirectory = $agent->getDataset()->getMountPoint();
        $volumes = $agent->getVolumes();

        $includedVolumes = new Volumes([]);
        foreach ($volumes as $volume) {
            if ($volume->isIncluded()) {
                $includedVolumes->addVolume($volume);
            }
        }

        return $this->createImagesFromVolumes($agent, $imagesDirectory, $includedVolumes);
    }

    /**
     * Create one DattoImage object for each volume in a given snapshot.
     *
     * @param Agent $agent
     * @param int $snapshotEpoch
     * @param string $imagesDirectory
     * @return DattoImage[]
     */
    public function createImagesForSnapshot(Agent $agent, int $snapshotEpoch, string $imagesDirectory): array
    {
        $agentSnapshot = $this->agentSnapshotService->get($agent->getKeyName(), $snapshotEpoch);
        $volumes = $agentSnapshot->getVolumes()->getArrayCopy();
        if (is_null($volumes)) {
            throw new Exception("No volumes found in {$agent->getKeyName()}@$snapshotEpoch");
        }

        $includedVolumesList = $agentSnapshot->getDesiredVolumes();
        $includedVolumes = new Volumes([]);
        foreach ($volumes as $volume) {
            $included = $includedVolumesList->isIncluded($volume->getGuid());
            if ($included) {
                $includedVolumes->addVolume($volume);
            }
        }

        return $this->createImagesFromVolumes($agent, $imagesDirectory, $includedVolumes);
    }

    /**
     * @param Agent $agent
     * @param string $imagesDirectory
     * @param Volumes $volumes
     * @return DattoImage[]
     */
    private function createImagesFromVolumes(Agent $agent, string $imagesDirectory, Volumes $volumes): array
    {
        $dattoImages = $this->directoryToImagesMapping[$imagesDirectory] ?? [];

        if (empty($dattoImages)) {
            foreach ($volumes as $volume) {
                $dattoImages[$volume->getGuid()] = new DattoImage(
                    $agent,
                    $volume,
                    $imagesDirectory,
                    $this->filesystem,
                    $this->blockDeviceManager,
                    $this->encryptionService
                );
            }
            $this->directoryToImagesMapping[$imagesDirectory] = $dattoImages;
        } else {
            $logger = LoggerFactory::getAssetLogger($agent->getKeyName());
            $logger->info(
                'DIF0001 Found preexisting image(s)',
                ['agentKeyName' => $agent->getKeyName(), 'dattoImages' => $dattoImages]
            );
        }

        return $dattoImages;
    }
}
