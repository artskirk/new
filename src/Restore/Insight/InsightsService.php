<?php
namespace Datto\Restore\Insight;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Asset;
use Datto\Config\AgentShmConfigFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Restore\CloneSpec;
use Datto\Utility\Screen;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\AssetCloneManager;
use Datto\ZFS\ZfsDatasetService;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Handles getting current and possible agents for backup comparisons
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class InsightsService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const SNAP_COMPARE_KEY_FORMAT = "/datto/config/keys/%s.snapCompare";
    const MFT_MOUNT_FORMAT = ZfsDatasetService::HOMEPOOL_DATASET_PATH . "/%s-*-mft";
    const MFT_MATCH_REGEX = "/.*-(.*)-mft$/";
    const SUFFIX_MFT = "mft";

    private AgentService $agentService;
    private Filesystem $filesystem;
    private AssetCloneManager $cloneManager;
    private FeatureService $featureService;
    private InsightsFactory $insightsFactory;

    /** @var InsightsResultsService */
    private $resultsService;

    /** @var Screen */
    private $screen;

    /** @var AgentShmConfigFactory */
    private $agentShmConfigFactory;

    /** @var Collector */
    private $collector;

    public function __construct(
        AgentService $agentService,
        Filesystem $filesystem,
        AssetCloneManager $cloneManager,
        FeatureService $featureService,
        InsightsFactory $insightsFactory,
        InsightsResultsService $resultsService,
        Screen $screen,
        AgentShmConfigFactory $agentShmConfigFactory,
        Collector $collector
    ) {
        $this->agentService = $agentService;
        $this->filesystem = $filesystem;
        $this->cloneManager = $cloneManager;
        $this->featureService = $featureService;
        $this->insightsFactory = $insightsFactory;
        $this->resultsService = $resultsService;
        $this->agentShmConfigFactory = $agentShmConfigFactory;
        $this->screen = $screen;
        $this->collector = $collector;
    }

    /**
     * Returns a list of all current compares
     *
     * @return BackupInsight[]
     */
    public function getCurrent(): array
    {
        $compareList = $this->filesystem->glob(sprintf(static::SNAP_COMPARE_KEY_FORMAT, "*"));
        $backupComparisons = [];
        $this->logger->info("INS0001 Looking for active snapshot comparisons.");
        foreach ($compareList as $compareFile) {
            try {
                $backupComparisons[] = $this->createBackupInsightFromFile($compareFile);
            } catch (\Throwable $e) {
                $this->logger->info('INS0009 File found but unable to find active insight.', ['compareFile' => $compareFile]);
            }
        }
        $totalFound = count($backupComparisons);
        $this->logger->info('INS0002 active snapshot comparisons found', ['totalFound' => $totalFound]);

        return $backupComparisons;
    }

    /**
     * Return a list of all compares for a particular asset.
     *
     * @param Asset $asset
     * @return BackupInsight[]
     */
    public function getCurrentByAsset(Asset $asset): array
    {
        $compareList = $this->filesystem->glob(sprintf(static::SNAP_COMPARE_KEY_FORMAT, $asset->getKeyName()));
        $backupComparisons = [];

        foreach ($compareList as $compareFile) {
            try {
                $backupComparisons[] = $this->createBackupInsightFromFile($compareFile);
            } catch (\Throwable $e) {
                $this->logger->info('INS0009 File found but unable to find active insight.', ['compareFile' => $compareFile]);
            }
        }

        return $backupComparisons;
    }

    /**
     * Returns all agents that support backup comparisons
     *
     * @return Agent[]
     */
    public function getComparableAgents(): array
    {
        $allAgents = $this->agentService->getAllLocal();
        $agents = [];

        foreach ($allAgents as $agent) {
            if ($this->featureService->isSupported(FeatureService::FEATURE_RESTORE_BACKUP_INSIGHTS, null, $agent)) {
                $agents[] = $agent;
            }
        }

        return $agents;
    }

    /**
     * Cleans up an existing mft comparison
     *
     * @param string $agentKey
     */
    public function remove(string $agentKey): void
    {
        $agent = $this->agentService->get($agentKey);
        $comparisonMounts = $this->getComparisonMounts($agentKey);
        if ($comparisonMounts) {
            $this->logger->info('INS0003 Attempting to clean comparison for agent', ['agentKey' => $agentKey]);

            $screenHash = "mftComp-$agentKey";

            if ($this->screen->isScreenRunning($screenHash)) {
                $this->screen->killScreen("mftComp-$agentKey");
            }

            foreach ($comparisonMounts as $existingPath) {
                try {
                    $this->unmountAndDestroyClone($agent, $existingPath);
                } catch (Throwable $e) {
                    $this->logger->error(
                        'INS0004 Unable to clean comparison for agent',
                        ['agentKey' => $agentKey, 'exception' => $e]
                    );
                    throw $e;
                }
            }
        }

        $this->removeCompareFiles($agentKey);

        $this->logger->info('INS0005 Successfully cleaned comparison for agent', ['agent' => $agentKey]);
    }

    /**
     * Checks to see if a file comparison already exists for the specified agent
     *
     * @param string $assetKey
     * @return bool
     */
    public function exists(string $assetKey): bool
    {
        $insightsFile = sprintf(static::SNAP_COMPARE_KEY_FORMAT, $assetKey);
        if ($this->filesystem->exists($insightsFile)) {
            $insightArray = json_decode($this->filesystem->fileGetContents($insightsFile), true);
            return $insightArray['complete'];
        }

        return false;
    }

    /**
     * Starts an insight immediately or in a screen.
     *
     * @param string $agentKey
     * @param int $firstPoint
     * @param int $secondPoint
     * @param bool $background
     */
    public function start(string $agentKey, int $firstPoint, int $secondPoint, bool $background = false): void
    {
        $agent = $this->agentService->get($agentKey);
        if (count($this->getCurrentByAsset($agent)) > 0) {
            throw new \Exception("Compare already exists for: $agentKey");
        }
        
        if ($background) {
            $this->startBackground($agentKey, $firstPoint, $secondPoint);
        } else {
            $this->startForeground($agentKey, $firstPoint, $secondPoint);
        }
    }

    /**
     * @param string $agentKey
     * @return InsightStatus
     */
    public function getStatus(string $agentKey): InsightStatus
    {
        $agentShmConfig = $this->agentShmConfigFactory->create($agentKey);

        $status = new InsightStatus();
        if ($agentShmConfig->loadRecord($status)) {
            return $status;
        }

        throw new \Exception("Unable to get insight status for $agentKey");
    }

    /**
     * @param string $agentKey
     * @param int $pointOne
     * @param int $pointTwo
     * @return InsightResult
     */
    public function getResults(string $agentKey, int $pointOne, int $pointTwo): InsightResult
    {
        $agent = $this->agentService->get($agentKey);

        $results = $this->resultsService->createResults($agent, $pointOne, $pointTwo);

        return $results;
    }

    /**
     * @param string $agentKey
     * @param int $firstPoint
     * @param int $secondPoint
     */
    private function startForeground(string $agentKey, int $firstPoint, int $secondPoint): void
    {
        $agent = $this->agentService->get($agentKey);
        $inspection = $this->insightsFactory->create($agent, $firstPoint, $secondPoint);
        $isReplicated = $agent->getOriginDevice()->isReplicated();
        try {
            $this->collector->increment(Metrics::INSIGHTS_STARTED, [
                'is_replicated' => $isReplicated
            ]);

            $inspection->commit();

            $this->collector->increment(Metrics::INSIGHTS_SUCCESS, [
                'is_replicated' => $isReplicated
            ]);
        } catch (Throwable $e) {
            $this->collector->increment(Metrics::INSIGHTS_FAILURE, [
                'is_replicated' => $isReplicated
            ]);
            throw $e;
        }
    }

    /**
     * @param string $agentKey
     * @param int $firstPoint
     * @param int $secondPoint
     */
    private function startBackground(string $agentKey, int $firstPoint, int $secondPoint): void
    {
        $command = ["snapctl", "restore:insight:create", $agentKey, $firstPoint, $secondPoint];
        if (!$this->screen->runInBackground($command, "mftComp-$agentKey")) {
            throw new \Exception("Unable to start comparison for: $agentKey");
        }
    }

    /**
     * @param string $agentKey
     * @return array|bool string[] if successful, false otherwise
     */
    private function getComparisonMounts(string $agentKey)
    {
        return $this->filesystem->glob(sprintf(static::MFT_MOUNT_FORMAT, $agentKey));
    }

    /**
     * Looking at:
     * /homePool/AgentName-<timestamp>-mft
     *
     * Matches:
     *  [0]: /homePool/AgentName-<timestamp>-mft
     *  [1]: <timestamp>
     *
     * @param string $mount
     * @return string
     */
    private function getPointFromMount($mount): string
    {
        $point = null;
        $homePool = ZfsDatasetService::HOMEPOOL_DATASET;
        if (preg_match("|\/$homePool\/.*-([0-9]*)-mft|", $mount, $matches)) {
            $point = $matches[1] ?? null;
        }

        if ($point === null) {
            throw new \Exception("Unable to get point from $mount");
        }

        return $point;
    }

    /**
     * @param Agent $agent
     * @param $path
     */
    private function unmountAndDestroyClone(Agent $agent, $path): void
    {
        $agentKey = $agent->getKeyName();
        $destroyPoint = $this->getPointFromMount($path);
        $this->logger->info('INS0006 Cleaning mft compare point', ['comparePointToDestroy' => $destroyPoint, 'agentKey' => $agentKey]);

        if ($destroyPoint !== null) {
            $cloneSpec = CloneSpec::fromAsset($agent, $destroyPoint, self::SUFFIX_MFT);

            try {
                $this->cloneManager->destroyClone($cloneSpec);
            } catch (Throwable $e) {
                throw new \Exception("Comparison for $destroyPoint still active.", $e->getCode(), $e);
            }
        }
    }

    /**
     * @param string $agentKey
     */
    private function removeCompareFiles(string $agentKey): void
    {
        $files = $this->resultsService->getResultsFiles($agentKey);
        foreach ($files as $file) {
            $this->filesystem->unlink($file);
        }
        $agentCompare = sprintf(static::SNAP_COMPARE_KEY_FORMAT, $agentKey);
        if ($this->filesystem->exists($agentCompare)) {
            $this->filesystem->unlink($agentCompare);
        }
        $statusFile = sprintf(InsightStatus::LOG_PATH_FORMAT, $agentKey);
        if ($this->filesystem->exists($statusFile)) {
            $this->filesystem->unlink($statusFile);
        }
    }

    /**
     * @param string $compareFile
     * @return BackupInsight
     */
    private function createBackupInsightFromFile(string $compareFile): BackupInsight
    {
        $agentKey = null;
        
        if (preg_match("|\/datto\/config\/keys/(.*).snapCompare|", $compareFile, $matches)) {
            if (isset($matches[1])) {
                $agentKey = $matches[1];
            }
        }
        if ($agentKey === null) {
            throw new \Exception("Unable to get agentName from compare file: " . $compareFile);
        }

        $insightArray = json_decode(trim($this->filesystem->fileGetContents($compareFile)), true);
        if (isset($insightArray['firstPoint']) && isset($insightArray['secondPoint'])) {
            $firstPoint = $insightArray['firstPoint'];
            $secondPoint = $insightArray['secondPoint'];
            $agent = $this->agentService->get($agentKey);

            return new BackupInsight($agent, $firstPoint, $secondPoint);
        }

        $comparisonMounts = $this->getComparisonMounts($agentKey);

        if (count($comparisonMounts) === 2) {
            preg_match(self::MFT_MATCH_REGEX, $comparisonMounts[0], $matches);
            $point1 = $matches[1] ?? null;
            preg_match(self::MFT_MATCH_REGEX, $comparisonMounts[1], $matches);
            $point2 = $matches[1] ?? null;

            if ($point1 === null || $point2 === null) {
                throw new \Exception(sprintf("Unable to determine points for %s comparison", $agentKey));
            }

            if ($point1 > $point2) {
                $tmp = $point2;
                $point2 = $point1;
                $point1 = $tmp;
            }
            /** @var Agent $agent */
            $agent = $this->agentService->get($agentKey);
            return new BackupInsight($agent, (int)$point1, (int)$point2);
        }


        throw new \Exception("Unable to create backup insight for " . $agentKey);
    }
}
