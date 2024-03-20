<?php

namespace Datto\Restore\Insight\InsightStages;

use Datto\Asset\Agent\DmCryptManager;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\Volume;
use Datto\Block\LoopManager;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentShmConfigFactory;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\Insight\BackupInsight;
use Datto\Restore\Insight\InsightsResultsService;
use Datto\Restore\Insight\InsightStatus;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Runs mftdump as a diff on the two snapshots tied to an insight.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class MftDumpStage extends InsightStage
{
    const HORUS_BIN = "/usr/bin/horus";
    const HORUS_TIMEOUT_SECONDS = DateTimeService::SECONDS_PER_HOUR * 3;

    private ProcessFactory $processFactory;

    /** @var InsightsResultsService */
    private $resultsService;

    /** @var LoopManager */
    private $loopManager;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var DmCryptManager */
    private $dmCryptManager;

    public function __construct(
        BackupInsight $insight,
        AssetCloneManager $cloneManager,
        Filesystem $filesystem,
        ProcessFactory $processFactory,
        DeviceLoggerInterface $loggerInterface,
        InsightsResultsService $resultsService,
        AgentShmConfigFactory $agentShmConfigFactory,
        LoopManager $loopManager,
        EncryptionService $encryptionService,
        DmCryptManager $dmCryptManager
    ) {
        $this->processFactory = $processFactory;
        $this->resultsService = $resultsService;
        $this->loopManager = $loopManager;
        $this->encryptionService = $encryptionService;
        $this->dmCryptManager = $dmCryptManager;

        parent::__construct($insight, $cloneManager, $filesystem, $agentShmConfigFactory, $loggerInterface);
    }

    /**
     * Run the mftdump binary against the two points
     */
    public function commit()
    {
        try {
            $agent = $this->insight->getAgent();
            $volumes = $agent->getVolumes();
            $pointOne = $this->insight->getFirstPoint();
            $pointTwo = $this->insight->getSecondPoint();
            $firstPath = $this->getCloneSpec($pointOne)->getTargetMountpoint();
            $secondPath = $this->getCloneSpec($pointTwo)->getTargetMountpoint();

            $this->writeStatus(InsightStatus::STATUS_CALCULATING);

            $reFsVols = $this->resultsService->getReFsVols($agent, $pointOne, $pointTwo);
            foreach ($volumes as $volume) {
                if (!in_array($volume->getGuid(), $reFsVols, true)) {
                    /** @var Volume $volume */
                    $this->runMftDump($firstPath, $secondPath, $volume);
                }
            }

            $this->writeStatus(InsightStatus::STATUS_COMPLETE, true);
        } catch (\Throwable $e) {
            $this->writeStatus(InsightStatus::STATUS_FAILED, true, true);
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // No cleanup
    }

    /**
     * Remove output files
     */
    public function rollback()
    {
        $outFiles = $this->resultsService->getResultsFiles($this->insight->getAgent()->getKeyName());
        foreach ($outFiles as $outFile) {
            $this->filesystem->unlink($outFile);
        }
        if ($this->filesystem->exists($this->getDumpFile())) {
            $this->filesystem->unlink($this->getDumpFile());
        }
    }

    /**
     * @param string $firstPath
     * @param string $secondPath
     * @param Volume $volume
     */
    private function runMftDump(string $firstPath, string $secondPath, Volume $volume)
    {
        $guid = $volume->getGuid();

        $firstVolume = "$firstPath/$guid" . AssetCloneManager::EXTENSION_DATTO;
        $secondVolume = "$secondPath/$guid" . AssetCloneManager::EXTENSION_DATTO;

        if (!($this->filesystem->exists($firstVolume) && $this->filesystem->exists($secondVolume))) {
            return;
        }

        $results = $this->resultsService->createVolumeResult(
            $this->insight->getAgent()->getKeyName(),
            $volume,
            $this->insight->getFirstPoint(),
            $this->insight->getSecondPoint()
        );

        $outFile = $results->getDiffFile();

        $firstLoop = $this->getLoopFromVolume($firstPath, $guid);
        $secondLoop = $this->getLoopFromVolume($secondPath, $guid);

        $process = $this->processFactory
            ->get([static::HORUS_BIN, '-f', 'json', '-j', $firstLoop, $secondLoop, '-g', '-s'])
            ->setTimeout(static::HORUS_TIMEOUT_SECONDS);

        $this->logger->info('INS0005 Calculating Differences', ['guid' => $guid]);

        try {
            $process->mustRun();

            $out = $process->getOutput();
            $this->filesystem->filePutContents($outFile, $out);
        } catch (\Throwable $e) {
            $this->logger->warning('INS0003 Commit failed, unable to run horus', ['guid' => $guid, 'exception' => $e]);
            throw $e;
        }
    }

    /**
     * @param $path
     * @param $guid
     * @return string
     */
    private function getLoopFromVolume($path, $guid): string
    {
        $encrypted = $this->encryptionService->isEncrypted($this->insight->getAgent()->getKeyName());

        if ($encrypted) {
            $encryptedVolume = "$path/$guid" . AssetCloneManager::EXTENSION_DETTO;

            $dmDevice = $this->dmCryptManager->getDMCryptDevicesForFile($encryptedVolume, true);

            if (isset($dmDevice[0])) {
                $dmDevice = $dmDevice[0];
            } else {
                throw new \Exception("INS0004 Unable to find dm devices for $guid");
            }

            $loopFile = $dmDevice;
        } else {
            $loopFile = "$path/$guid" . AssetCloneManager::EXTENSION_DATTO;
        }

        $loop = $this->loopManager->getLoopsOnFile($loopFile);

        if (isset($loop[0])) {
            $loop = $loop[0];
        } else {
            throw new \Exception("INS0004 Unable to find loop devices for $guid");
        }

        $loop = $loop->getPathToPartition(1);

        return $loop;
    }
}
