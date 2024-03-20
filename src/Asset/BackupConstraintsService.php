<?php

namespace Datto\Asset;

use Datto\Asset\Agent\Volume;
use Datto\Asset\Agent\Volumes;
use Datto\Asset\AssetInfoSyncService;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\VolumesService;
use Datto\Config\AgentStateFactory;
use Datto\Resource\DateTimeService;
use Datto\Utility\ByteUnit;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Class to evaluate various backup constraints an asset could have
 */
class BackupConstraintsService
{
    /** @var AgentStateFactory */
    private $agentStateFactory;

    /** @var AssetInfoSyncService */
    private $assetInfoSyncService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var VolumesService */
    private $volumesService;

    /**
     * @param AgentStateFactory $agentStateFactory
     * @param AssetInfoSyncService $assetInfoSyncService
     * @param DateTimeService $dateTimeService
     * @param DeviceLoggerInterface $logger
     * @param VolumesService $volumesService
     */
    public function __construct(
        AgentStateFactory $agentStateFactory,
        AssetInfoSyncService $assetInfoSyncService,
        DateTimeService $dateTimeService,
        DeviceLoggerInterface $logger,
        VolumesService $volumesService
    ) {
        $this->agentStateFactory = $agentStateFactory;
        $this->assetInfoSyncService = $assetInfoSyncService;
        $this->dateTimeService = $dateTimeService;
        $this->logger = $logger;
        $this->volumesService = $volumesService;
    }

    /**
     * @param Agent $agent
     * @param bool $syncResults
     *
     * @return BackupConstraintsResult
     */
    public function enforce(Agent $agent, bool $syncResults = true): BackupConstraintsResult
    {
        $backupConstraintsResult = new BackupConstraintsResult();

        $volumes = $agent->getVolumes();
        if (count($volumes) < 1) {
            $this->logger->info('BCS0001 Agent has no included volumes.', [
                'agentUuid' => $agent->getKeyName()
            ]);
        }
        $totalVolumeSize = $this->volumesService->getIncludedTotalVolumeSizeInBytes($volumes);

        $this->validateMaxTotalVolumeConstraint($agent, $totalVolumeSize, $syncResults, $backupConstraintsResult);

        return $backupConstraintsResult;
    }

    public function validateSetIncludes(Agent $agent, array $includedGuidData): BackupConstraintsResult
    {
        $backupConstraintsResult = new BackupConstraintsResult();

        $volumes = $agent->getVolumes();
        $potentialFinalIncludedVolumes = new Volumes();

        foreach ($volumes as $volume) {
            /** @var Volume $volume */

            // calculate size using $includedGuidData
            if (isset($includedGuidData[$volume->getGuid()])) {
                if ($includedGuidData[$volume->getGuid()]) {
                    $potentialFinalIncludedVolumes->addVolume($volume);
                }
            } elseif ($volume->isIncluded()) {
                $potentialFinalIncludedVolumes->addVolume($volume);
            }
        }

        $totalVolumeSize = $this->volumesService->getTotalVolumeSizeInBytes($potentialFinalIncludedVolumes);

        $this->validateMaxTotalVolumeConstraint($agent, $totalVolumeSize, false, $backupConstraintsResult);

        return $backupConstraintsResult;
    }

    /**
     * @param Agent $agent
     * @param bool $syncResults
     * @param BackupConstraintsResult $result
     *
     * @throws Exception
     */
    private function validateMaxTotalVolumeConstraint(Agent $agent, int $totalVolumeSize, bool $syncResults, BackupConstraintsResult $result): void
    {
        $assetKey = $agent->getKeyName();

        $constraints = $agent->getBackupConstraints();
        $maxTotalVolumeSize = $constraints ? $constraints->getMaxTotalVolumeSize() : null;
        if ($maxTotalVolumeSize === null) {
            throw new Exception('Agent does not have backup constraints set.');
        }

        $isValidVolumeConfiguration = $totalVolumeSize > 0 && $totalVolumeSize <= $maxTotalVolumeSize;

        if ($syncResults) {
            $this->storeVolumeValidationResults($assetKey, $isValidVolumeConfiguration);
        }

        $this->logger->info('BCS0001 Agent volume configuration validated.', [
            'agentUuid' => $agent->getKeyName(),
            'totalVolumeSize' => $totalVolumeSize,
            'maxTotalVolumeSize' => $maxTotalVolumeSize,
            'result' => $isValidVolumeConfiguration
        ]);

        $result->setMaxTotalVolumeResult($isValidVolumeConfiguration);
        if ($isValidVolumeConfiguration) {
            $result->setMaxTotalVolumeMessage(
                sprintf(
                    BackupConstraintsResult::MAX_TOTAL_VOLUME_CONSTRAINT_SUCCESS,
                    round(ByteUnit::BYTE()->toGiB($totalVolumeSize), 2)
                )
            );
        } else {
            $result->setMaxTotalVolumeMessage(
                sprintf(
                    BackupConstraintsResult::MAX_TOTAL_VOLUME_CONSTRAINT_FAILURE,
                    round(ByteUnit::BYTE()->toGiB($totalVolumeSize), 2)
                )
            );
        }
    }

    /**
     * @param string $assetKey
     * @param bool $isValidVolumeConfiguration
     */
    private function storeVolumeValidationResults($assetKey, $isValidVolumeConfiguration): void
    {
        $agentState = $this->agentStateFactory->create($assetKey);
        $agentState->set('lastVolumeValidationCheck', $this->dateTimeService->getTime());
        $agentState->set('lastVolumeValidationResult', $isValidVolumeConfiguration);

        $this->assetInfoSyncService->sync($assetKey);
    }
}
