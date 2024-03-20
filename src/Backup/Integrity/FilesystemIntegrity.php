<?php
namespace Datto\Backup\Integrity;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DattoImage;
use Datto\Asset\Agent\VolumeMetadata;
use Datto\Feature\FeatureService;
use Datto\Filesystem\FilesystemCheck;
use Datto\Filesystem\FilesystemCheckResult;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Datto\Service\Verification\Local\FilesystemIntegrityCheckReportService;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Checks backed up volumes for filesystem corruption.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 * @author Christopher LaRosa (clarosa@datto.com)
 */
class FilesystemIntegrity implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private Filesystem $filesystem;
    private FeatureService $featureService;
    private AgentService $agentService;
    private FilesystemCheck $filesystemCheck;
    private FilesystemIntegrityCheckReportService $filesystemIntegrityCheckReportService;

    private Agent $agent;
    private int $snapshotEpoch;
    /** @var string[] */
    private array $includedGuids;
    /** @var DattoImage[] */
    private array $dattoImages;

    public function __construct(
        Filesystem $filesystem,
        FeatureService $featureService,
        AgentService $agentService,
        FilesystemCheck $filesystemCheck,
        FilesystemIntegrityCheckReportService $filesystemIntegrityCheckReportService
    ) {
        $this->filesystem = $filesystem;
        $this->featureService = $featureService;
        $this->agentService = $agentService;
        $this->filesystemCheck = $filesystemCheck;
        $this->filesystemIntegrityCheckReportService = $filesystemIntegrityCheckReportService;
    }

    public function isSupported(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_FILESYSTEM_INTEGRITY_CHECK);
    }

    /**
     * Verify integrity of all agent filesystems, logs information
     * to a filesystemIntegrityReport file that is specific to the agent and snapshot epoch.
     *
     * @param Agent $agent
     * @param DattoImage[] $dattoImages
     * @param int $snapshotEpoch
     * @param string[] $includedGuids
     *
     * @return FilesystemCheckResult[]
     */
    public function verifyIntegrity(
        Agent $agent,
        array $dattoImages,
        int $snapshotEpoch,
        array $includedGuids
    ): array {
        $this->agent = $agent;
        $this->dattoImages = $dattoImages;
        $this->snapshotEpoch = $snapshotEpoch;
        $this->includedGuids = $includedGuids;

        $this->logger->setAssetContext($this->agent->getKeyName());

        if (!$this->isSupported()) {
            $this->logger->debug('BAK3606 Skipping filesystem integrity verification per configuration.');
            return [];
        }

        $report = [];
        foreach ($this->dattoImages as $dattoImage) {
            $volume = $dattoImage->getVolume();
            $volumeGuid = $volume->getGuid();
            $volumeMountPoint = $volume->getMountpoint() ?: $volume->getLabel();
            $volumeMetadata = new VolumeMetadata($volumeMountPoint, $volumeGuid);

            // Asset logs cannot have colons, so let's remove the trailing part (windows, C:\ becomes C).
            $cleanMountPoint = str_replace(':\\', '', $volumeMountPoint);

            if (!in_array($volumeGuid, $this->includedGuids)) {
                $this->logger->debug('BAK3603 Volume is not currently included, skipping', ['volumeGuid' => $volumeGuid]);
                continue;
            }

            try {
                $this->logger->info('BAK3601 Verifying filesystem integrity of the volume', ['mountPoint' => $cleanMountPoint, 'volumeGuid' => $volumeGuid]);

                // Run the check and save the result
                $report[$volumeGuid] = $this->filesystemCheck->execute(
                    $dattoImage->getPathToPartition(),
                    $volumeMetadata
                );
            } catch (Throwable $ex) {
                $this->logger->error('BAK3602 Caught error while attempting to verify integrity', [
                    'mountPoint' => $cleanMountPoint,
                    'volumeGuid' => $volumeGuid,
                    'exception' => $ex
                ]);
            }
        }

        $encodedReport = json_encode($this->toArray($report));
        $this->filesystemIntegrityCheckReportService->save(
            $this->agent->getKeyName(),
            $this->snapshotEpoch,
            $encodedReport
        );
        $this->updateRecoveryPoints($this->agent, $report);

        return $report;
    }

    /**
     * @param Agent $agent
     * @param FilesystemCheckResult[] $filesytemCheckResults
     */
    private function updateRecoveryPoints(Agent $agent, array $filesytemCheckResults)
    {
        $recoveryPoints = $agent->getLocal()->getRecoveryPoints();
        $recoveryPoint = $recoveryPoints->get($this->snapshotEpoch);
        $recoveryPoint->setFilesystemCheckResults($filesytemCheckResults);
        $this->agentService->save($agent);
    }

    /**
     * @param FilesystemCheckResult[] $checkResults
     * @return array
     */
    private function toArray(array $checkResults): array
    {
        $arrayResults = [];

        foreach ($checkResults as $guid => $checkResult) {
            $arrayResults[$guid] = $checkResult->toArray();
        }

        return $arrayResults;
    }
}
