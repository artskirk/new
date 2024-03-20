<?php

namespace Datto\Backup;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\VolumeMetadata;
use Datto\Asset\AssetType;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Common\Utility\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Events\EventService;
use Datto\Filesystem\FilesystemCheckResult;
use Datto\Log\CodeCounter;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Resource\DateTimeService;
use Datto\Utility\ByteUnit;
use Exception;
use Monolog\Logger;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Class responsible for sending backup related events to any listeners.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class BackupEventService extends EventService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var Collector */
    private $collector;

    public function __construct(
        Filesystem $filesystem,
        DeviceConfig $deviceConfig,
        DateTimeService $dateTimeService,
        Collector $collector
    ) {
        parent::__construct($filesystem, $deviceConfig);

        $this->dateTimeService = $dateTimeService;
        $this->collector = $collector;
    }

    /**
     * @param BackupContext $backupContext
     * @param Throwable|null $throwable
     */
    public function dispatchAgentCompleted(BackupContext $backupContext, Throwable $throwable = null)
    {
        if (!$backupContext->getAsset()->isType(AssetType::AGENT)) {
            throw new Exception("EVT0006 Asset must be of type 'agent'");
        }

        /** @var Agent $agent */
        $agent = $backupContext->getAsset();

        $context = $this->getCommonContext();

        $context['success'] = $throwable === null;
        $context['agent_key'] = $agent->getKeyName();
        $context['agent_api_version'] = $agent->getDriver()->getApiVersion();
        $context['agent_encrypted'] = $agent->getEncryption()->isEnabled();
        $context['agent_type'] = $agent->getPlatform()->value();

        if ($throwable !== null) {
            $context['exception_error_message'] = $throwable->getMessage();
            $context['exception_stack_trace'] = $throwable->getTraceAsString();
        }

        $context['backup_start_time'] = $backupContext->getStartTime();
        $context['backup_end_time'] = $this->dateTimeService->getTime();
        $context['backup_duration'] = $context['backup_end_time'] - $context['backup_start_time'];
        $context['backup_size'] = $backupContext->getAmountTransferred();

        $this->logger->info('BAK4202 Backup operation completed.', $context);

        /**
         * We need to make sure that these context updates come after any logging that uses 'context.'
         * We do this because these types of metrics mess with our ElasticSearch mapping.
         * TODO: Remove these types of metrics entirely.
         */
        $context['backup_type_counts'] = $this->collectBackupTypeCounts($backupContext->getVolumeBackupTypes());

        $this->handleEventContext($context);

        if ($backupContext->isExpectRansomwareChecks()) {
            $ransomwareResults = $backupContext->getRansomwareResults();

            $result = 'process_failed';
            if ($ransomwareResults !== null) {
                if ($ransomwareResults->hasRansomware()) {
                    $result = 'has_ransomware';
                } else {
                    $result = 'no_ransomware';
                }
            }

            $this->collector->increment(Metrics::AGENT_RANSOMWARE_PROCESS, [
                'result' => $result
            ]);
        }

        if ($backupContext->isExpectFilesystemChecks()) {
            $results = $backupContext->getFilesystemCheckResults();

            // Fill results with any missing check results as a catch-all if a volume was not processed
            $volumesProcessed = count($results);
            $expectedVolumesCount = count($agent->getIncludedVolumesSettings()->getIncludedList()) -
                count($backupContext->getMissingVolumesResult() ?? []);
            $notProcessedCount = $expectedVolumesCount - $volumesProcessed;
            for ($i = 0; $i < $notProcessedCount; $i++) {
                $results[] = new FilesystemCheckResult(
                    FilesystemCheckResult::RESULT_UNKNOWN_ISSUE,
                    new VolumeMetadata('', '') // not used
                );
            }

            // Write a metric for each result
            foreach ($results as $volumeResult) {
                $this->collector->increment(Metrics::AGENT_FILESYSTEM_INTEGRITY_PROCESS, [
                    'result' => $volumeResult->getResultCode()
                ]);
            }
        }

        if ($backupContext->isExpectMissingVolumesChecks()) {
            $results = [];
            $missingVolumesResult = $backupContext->getMissingVolumesResult();

            if ($missingVolumesResult !== null) {
                // Capture a result for every missing volume
                foreach ($missingVolumesResult as $missingVolume) {
                    $results[] = 'is_missing';
                }

                // Fill the results with "volume present" for all volumes that were present
                $missingCount = count($results);
                $expectedVolumesCount = count($agent->getIncludedVolumesSettings()->getIncludedList());
                $volumesPresentCount = $expectedVolumesCount - $missingCount;
                for ($i = 0; $i < $volumesPresentCount; $i++) {
                    $results[] = 'not_missing';
                }
            } else {
                // We didn't have any missing volume results, so assume the process failed
                $expectedVolumesCount = count($agent->getIncludedVolumesSettings()->getIncludedList());
                for ($i = 0; $i < $expectedVolumesCount; $i++) {
                    $results[] = 'process_failed';
                }
            }

            // Write a metric for each result
            foreach ($results as $result) {
                $this->collector->increment(Metrics::AGENT_MISSING_VOLUME_PROCESS, [
                    'result' => $result
                ]);
            }
        }
    }

    /**
     * @param Agent $agent
     */
    public function dispatchAgentStarted(Agent $agent)
    {
        $context = [];
        $context['agent_api_version'] = $agent->getDriver()->getApiVersion();
        $context['agent_encrypted'] = $agent->getEncryption()->isEnabled();
        $context['agent_type'] = $agent->getPlatform()->value();
        $this->logger->info('BAK4201 Backup operation started.', $context);

        $this->collector->increment(Metrics::AGENT_BACKUP_STARTED, $context);
    }

    /**
     * @param array $backupTypes
     * @return array
     */
    private function collectBackupTypeCounts(array $backupTypes)
    {
        $counts = [
            'differential' => 0,
            'full' => 0,
            'incremental' => 0
        ];

        foreach ($backupTypes as $guid => $backupType) {
            switch ($backupType) {
                case RecoveryPoint::VOLUME_BACKUP_TYPE_DIFFERENTIAL:
                    $counts['differential'] += 1;
                    break;

                case RecoveryPoint::VOLUME_BACKUP_TYPE_FULL:
                    $counts['full'] += 1;
                    break;

                case RecoveryPoint::VOLUME_BACKUP_TYPE_INCREMENTAL:
                    $counts['incremental'] += 1;
                    break;
            }
        }

        return $counts;
    }

    /**
     * {@inheritdoc}
     */
    private function handleEventContext(array $context)
    {
        if ($context['success']) {
            $this->incrementBackupSuccess($context);
        } else {
            $this->incrementBackupFail($context);
        }

        $this->incrementBackupTypes($context);
        $this->measureBackupDuration($context);

        $this->collector->flush();
    }

    /**
     * @param array $context
     */
    private function measureBackupDuration(array $context)
    {
        $this->collector->measure(Metrics::AGENT_BACKUP_DURATION, $context['backup_duration'], [
            'agent_type' => $context['agent_type'],
            'agent_encrypted' => $context['agent_encrypted'],
            'backup_size' => $this->getBackupSizeTag($context['backup_size']),
            'worst_backup_type' => $this->getWorstBackupTypeTag($context['backup_type_counts'])
        ]);
    }

    /**
     * @param array $context
     */
    private function incrementBackupTypes(array $context)
    {
        foreach ($context['backup_type_counts'] as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->collector->increment(sprintf(Metrics::AGENT_BACKUP_TYPE_FORMAT, $type), [
                    'agent_type' => $context['agent_type'],
                    'agent_encrypted' => $context['agent_encrypted']
                ]);
            }
        }
    }

    /**
     * @param array $context
     */
    private function incrementBackupSuccess(array $context)
    {
        $this->collector->increment(Metrics::AGENT_BACKUP_SUCCESS, [
            'agent_type' => $context['agent_type'],
            'agent_api_version' => $context['agent_api_version'],
            'agent_encrypted' => $context['agent_encrypted']
        ]);
    }

    /**
     * @param array $context
     */
    private function incrementBackupFail(array $context)
    {
        $this->collector->increment(Metrics::AGENT_BACKUP_FAIL, [
            'agent_type' => $context['agent_type'],
            'agent_api_version' => $context['agent_api_version'],
            'agent_encrypted' => $context['agent_encrypted']
        ]);
    }

    private function getCommonContext() : array
    {
        return [
            'device_os2_version' => $this->deviceConfig->getOs2Version(),
            'device_image_version' => $this->deviceConfig->getImageVersion()
        ];
    }

    private function getBackupSizeTag(int $backupSize) : string
    {
        $sizeInGib = ByteUnit::BYTE()->toGiB($backupSize);

        if ($sizeInGib === 0) {
            $bucket = 0;
        } elseif ($sizeInGib < 10) {
            // bucket by ones
            $bucket = round($sizeInGib);
            if ($bucket < 1) {
                $bucket = 'lessThan1';
            }
        } elseif ($sizeInGib < 100) {
            // bucket by tens
            $bucket = round($sizeInGib / 10) * 10;
        } elseif ($sizeInGib < 1000) {
            // bucket by hundreds
            $bucket = round($sizeInGib / 100) * 100;
        } else {
            // bucket by thousands
            $bucket = round($sizeInGib / 1000) * 1000;
        }

        return $bucket . 'G';
    }

    private function getWorstBackupTypeTag(array $backupTypeCounts) : string
    {
        if ($backupTypeCounts['full'] > 0) {
            return RecoveryPoint::VOLUME_BACKUP_TYPE_FULL;
        } elseif ($backupTypeCounts['differential'] > 0) {
            return RecoveryPoint::VOLUME_BACKUP_TYPE_DIFFERENTIAL;
        } elseif ($backupTypeCounts['incremental'] > 0) {
            return RecoveryPoint::VOLUME_BACKUP_TYPE_INCREMENTAL;
        }

        return 'unknown';
    }
}
