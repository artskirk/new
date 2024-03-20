<?php

namespace Datto\Asset\Agent;

use Datto\Asset\BackupConstraintsService;
use Datto\Cloud\AgentVolumeService;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Deals with including/excluding volumes whilst also checking against the
 * BackupconstraintsService.
 */
class VolumeInclusionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AgentService $agentService;
    private AgentVolumeService $agentVolumeService;
    private BackupConstraintsService $backupConstraintsService;
    private VolumesService $volumesService;
    private FeatureService $featureService;

    public function __construct(
        AgentService $agentService,
        AgentVolumeService $agentVolumeService,
        BackupConstraintsService $backupConstraintsService,
        VolumesService $volumesService,
        FeatureService $featureService
    ) {
        $this->agentService = $agentService;
        $this->agentVolumeService = $agentVolumeService;
        $this->backupConstraintsService = $backupConstraintsService;
        $this->volumesService = $volumesService;
        $this->featureService = $featureService;
    }

    /**
     * Exclude a batch of guids for an agent.
     */
    public function excludeGuids(Agent $agent, array $guids) : bool
    {
        $this->logger->setAssetContext($agent->getKeyName());
        $success = true;
        foreach ($guids as $guid) {
            if (!$this->volumesService->isIncluded($agent->getKeyName(), $guid)) {
                continue;
            }

            $this->volumesService->excludeByGuid($agent->getKeyName(), $guid);

            if ($this->featureService->isSupported(FeatureService::FEATURE_AGENT_BACKUP_CONSTRAINTS, null, $agent)) {
                $validationResult = $this->backupConstraintsService->enforce($agent, false);
                if (!$validationResult->getMaxTotalVolumeResult()) {
                    $this->logger->error("VIN0001 Could not exclude volume because validation failed.");
                    $success = false;
                }
            }
        }

        if ($success) {
            // ensure volume info in db is updated
            $this->agentVolumeService->update($agent->getKeyName());
        }

        return $success;
    }


    /**
     * Include a batch of guids for an agent.
     */
    public function includeGuids(Agent $agent, array $guids) : bool
    {
        $this->logger->setAssetContext($agent->getKeyName());
        $success = true;
        foreach ($guids as $guid) {
            if ($this->volumesService->isIncluded($agent->getKeyName(), $guid)) {
                continue;
            }

            $this->volumesService->includeByGuid($agent->getKeyName(), $guid);

            if ($this->featureService->isSupported(FeatureService::FEATURE_AGENT_BACKUP_CONSTRAINTS, null, $agent)) {
                $validationResult = $this->backupConstraintsService->enforce($agent, false);
                if (!$validationResult->getMaxTotalVolumeResult()) {
                    $this->logger->error("VIN0002 Could not include volume because validation failed.");
                    $success = false;
                }
            }
        }

        if ($success) {
            $this->agentService->save($agent);
            // ensure volume info in db is updated
            $this->agentVolumeService->update($agent->getKeyName());
        }

        return $success;
    }

    /**
     * Update volume included/excluded state.
     */
    public function setIncludedGuids(Agent $agent, array $includedGuidData): void
    {
        if ($this->featureService->isSupported(FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS)) {
            $volumes = $agent->getVolumes();
            foreach ($volumes as $volume) {
                /** @var Volume $volume */

                if ($volume->isOsVolume()) {
                    if (isset($includedGuidData[$volume->getGuid()]) && !$includedGuidData[$volume->getGuid()]) {
                        throw new Exception(
                            "VINOSV Could not set volumes because os volume would be excluded.",
                            400
                        );
                    }

                    break;
                }
            }
        }

        if ($this->featureService->isSupported(FeatureService::FEATURE_AGENT_BACKUP_CONSTRAINTS, null, $agent)) {
            $validationResult = $this->backupConstraintsService->validateSetIncludes($agent, $includedGuidData);
            if (!$validationResult->getMaxTotalVolumeResult()) {
                throw new Exception(
                    "VINVAL Could not set volumes because backup constraint validation failed.",
                    400
                );
            }
        }

        try {
            foreach ($includedGuidData as $guid => $included) {
                if ($included) {
                    $this->volumesService->includeByGuid($agent->getKeyName(), $guid);
                } else {
                    $this->volumesService->excludeByGuid($agent->getKeyName(), $guid);
                }
            }
        } finally {
            // ensure volume info in db is updated
            $this->agentVolumeService->update($agent->getKeyName());
        }
    }
}
