<?php

namespace Datto\Asset\Agent\Job;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\Volume;
use Datto\Log\LoggerFactory;
use Throwable;

/**
 * Retrieves Agent backup job status information.
 *
 * @author John Roland <jroland@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class AgentJobStatusRetriever
{
    /** @var AgentApiFactory */
    private $agentApiFactory;

    /** @var LoggerFactory */
    private $loggerFactory;

    public function __construct(
        AgentApiFactory $agentApiFactory = null,
        LoggerFactory $loggerFactory = null
    ) {
        $this->agentApiFactory = $agentApiFactory ?: new AgentApiFactory();
        $this->loggerFactory = $loggerFactory ?: new LoggerFactory();
    }

    /**
     * Get Agent backup job status information.
     *
     * @param Agent $agent
     * @return JobList[]
     */
    public function get(Agent $agent)
    {
        $agentApi = $this->agentApiFactory->createFromAgent($agent);

        $jobLists = array();
        $jobs = $this->getAllJobs($agentApi, $agent->getKeyName());

        foreach ($jobs as $jobTransferState => $jobIds) {
            $jobList = new JobList($jobTransferState, array());

            // Only take most recent 3 jobIds
            $jobIds = array_slice($jobIds, -3);

            foreach ($jobIds as $jobId) {
                $backupStatus = $agentApi->updateBackupStatus($jobId);

                // Skip any jobs that did not return a valid response
                if (empty($backupStatus)) {
                    continue;
                }

                $volumeGuids = $backupStatus->getVolumeGuids();
                foreach ($volumeGuids as $volumeGuid) {
                    // Some information was already filled in when the backup status was retrieved
                    $backupStatusVolumeDetails = $backupStatus->getVolumeDetails($volumeGuid);

                    $datetime = $backupStatusVolumeDetails->getDateTime();
                    $status = $backupStatusVolumeDetails->getStatus();
                    $bytesTotal = $backupStatusVolumeDetails->getBytesTotal();
                    $bytesSent = $backupStatusVolumeDetails->getBytesSent();

                    // For the rest of the information, just gather the current state from the agent config
                    $volume = $this->retrieveVolumeData($agent, $volumeGuid);

                    if ($volume) {
                        $volumeMountPoint = $volume->getMountpoint();
                        $volumeType = $backupStatusVolumeDetails->getVolumeType() ?: $volume->getVolumeType();
                        $filesystemType = $volume->getFilesystem();
                        $spaceTotal = $volume->getSpaceTotal();  // Might not be accurate if the volume got resized
                        $spaceFree = $volume->getSpaceFree(); // It will be the same for all jobs with the same volume
                        $spaceUsed = $volume->getSpaceUsed(); // It will be the same for all jobs with the same volume
                    } else {
                        $volumeMountPoint = null;
                        $volumeType = null;
                        $filesystemType = null;
                        $spaceTotal = null;
                        $spaceFree = null;
                        $spaceUsed = null;
                    }

                    $updatedVolumeDetails = new BackupJobVolumeDetails(
                        $datetime,
                        $status,
                        $volumeGuid,
                        $volumeMountPoint,
                        $volumeType,
                        $filesystemType,
                        $bytesTotal,
                        $bytesSent,
                        $spaceTotal,
                        $spaceFree,
                        $spaceUsed
                    );

                    $backupStatus->setVolumeDetails($updatedVolumeDetails);
                }

                $newJobs = $jobList->getJobs();
                $newJobs[] = $backupStatus;
                $jobList->setJobs($newJobs);
            }

            $jobLists[] = $jobList;
        }

        return $jobLists;
    }

    /**
     * @param AgentApi $agentApi
     * @return array
     *      Example return array (similar for all agent platforms):
     *      Array
     *      (
     *          [active] => Array
     *          (
     *              [0] => ae18637bb354438f88799209e3ad0cde
     *              [1] => d2559d4d2b8b46298e9e4d162be27f97
     *          )
     *          [failed] => Array
     *          (
     *              [0] => 6794018423884ae9a217f57135b527e3
     *              [1] => 2ff187150a8645f7aec4188d28781a5b
     *          )
     *          [finished] => Array
     *          (
     *              [0] => abf1d72204c4412088bf17d3246b4abe
     *              [1] => 04c123ad3fa04cb68b21533d1198463b
     *              [2] => cb40bafc41ab4f0d84795619ce9f01cc
     *          )
     *          [rollback] => Array
     *          (
     *          )
     *          [aborted] => Array
     *          (
     *          )
     *      )
     */
    private function getAllJobs(AgentApi $agentApi, string $agentKeyName): array
    {
        try {
            $allJobs = $agentApi->updateBackupStatus('');
        } catch (Throwable $e) {
            $logger = $this->loggerFactory->getAsset($agentKeyName);
            $logger->error('AJR0001 Unexpected exception retrieving job statuses', ['exception'=>$e]);
        }
        $allJobs = isset($allJobs) && is_array($allJobs) ? $allJobs : [];
        return $allJobs;
    }

    /**
     * @param Agent $agent
     * @param $volumeGuid
     * @return Volume
     */
    private function retrieveVolumeData(Agent $agent, $volumeGuid)
    {
        $matchedVolume = null;
        $volumes = $agent->getVolumes();
        foreach ($volumes as $volume) {
            if ($volume->getGuid() === $volumeGuid) {
                $matchedVolume = $volume;
                break;
            }
        }
        return $matchedVolume;
    }
}
