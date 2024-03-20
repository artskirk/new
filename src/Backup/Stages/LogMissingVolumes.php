<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\VolumesService;

/**
 * Logs missing volumes that are no longer reported by the agent.
 */
class LogMissingVolumes extends BackupStage
{

    /** @var VolumesService */
    private $volumeService;

    public function __construct(
        VolumesService $volumesService
    ) {
        $this->volumeService = $volumesService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->initializeApi();

        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $missingVolumesMetadata = $this->volumeService->getAllMissingVolumeMetadata($agent->getKeyName());
        if (!empty($missingVolumesMetadata)) {
            $missingVolumeContext = [];
            $misingVolumeMountPoints = [];
            foreach ($missingVolumesMetadata as $missingVolumeMetadata) {
                $missingVolumeContext[] = $missingVolumeMetadata->toArray();
                $misingVolumeMountPoints[] = $missingVolumeMetadata->getMountPoint();
            }
            $logMessage = 'The following previously included volumes were not backed up because they were unavailable during the backup process';
            $this->context->getLogger()->warning(
                'VSV0012 ' . $logMessage,
                [
                    'partnerAlertMessage' => $logMessage . ': ' . implode(', ', $misingVolumeMountPoints),
                    'missingVolumesMetadata' => $missingVolumeContext
                ],
            );
        } else {
            $this->context->clearAlert('VSV0012');
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        $this->context->getAgentApi()->cleanup();
    }

    private function initializeApi()
    {
        $this->context->getAgentApi()->initialize();
    }
}
