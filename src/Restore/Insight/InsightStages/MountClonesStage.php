<?php

namespace Datto\Restore\Insight\InsightStages;

use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\MountLoopHelper;
use Datto\Block\LoopInfo;
use Datto\Common\Resource\Process;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentShmConfigFactory;
use Datto\Filesystem\Exceptions\MountException;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\Insight\BackupInsight;
use Datto\Restore\Insight\InsightStatus;
use Datto\System\MountManager;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\Restore\Insight\InsightsResultsService;

/**
 * Creates loops and mounts them on each zfs clone created for the insight.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class MountClonesStage extends InsightStage
{
    const DATTO_GLOB_PATTERN = "/*.datto";

    const MOUNT_POINT_FORMAT = "/datto/mounts/%s/%s/%s";

    const MKDIR_MODE = 0777;

    /** @var MountManager */
    private $mountManager;

    /** @var MountLoopHelper */
    private $mountLoopHelper;

    private ProcessFactory $processFactory;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var InsightsResultsService */
    private $insightsResultsService;

    public function __construct(
        BackupInsight $insight,
        AssetCloneManager $cloneManager,
        Filesystem $filesystem,
        MountManager $mountManager,
        MountLoopHelper $mountLoopHelper,
        ProcessFactory $processFactory,
        EncryptionService $encryptionService,
        DeviceLoggerInterface $logger,
        AgentShmConfigFactory $agentShmConfigFactory,
        InsightsResultsService $insightsResultsService
    ) {
        parent::__construct($insight, $cloneManager, $filesystem, $agentShmConfigFactory, $logger);
        $this->encryptionService = $encryptionService;
        $this->mountManager = $mountManager;
        $this->mountLoopHelper = $mountLoopHelper;
        $this->processFactory = $processFactory;
        $this->insightsResultsService = $insightsResultsService;
    }

    /**
     * Create loops, mount them
     */
    public function commit()
    {
        try {
            $loopInfos = $this->attachLoops();
            $this->mountDevices($loopInfos);
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
     * {@inheritdoc}
     */
    public function rollback()
    {
        // No rollback, everything is taken care of in the CloneSnapshotStage class
    }

    /**
     * @return LoopInfo[]
     */
    private function attachLoops(): array
    {
        $loopInfos = [];

        $firstSnapshotEpoch = $this->insight->getFirstPoint();
        $secondSnapshotEpoch = $this->insight->getSecondPoint();

        $loopInfos[$firstSnapshotEpoch] = $this->attachLoopsForSingleSnapshot($firstSnapshotEpoch);
        $loopInfos[$secondSnapshotEpoch] = $this->attachLoopsForSingleSnapshot($secondSnapshotEpoch);

        return $loopInfos;
    }

    /**
     * @param LoopInfo[] $loopInfos
     */
    private function mountDevices(array $loopInfos)
    {
        $firstSnapshotEpoch = $this->insight->getFirstPoint();
        $secondSnapshotEpoch = $this->insight->getSecondPoint();

        $this->mountDevicesForSingleSnapshot($firstSnapshotEpoch, $loopInfos[$firstSnapshotEpoch]);
        $this->mountDevicesForSingleSnapshot($secondSnapshotEpoch, $loopInfos[$secondSnapshotEpoch]);
    }

    /**
     * @param int $snapshotEpoch
     * @return array
     */
    private function attachLoopsForSingleSnapshot(int $snapshotEpoch): array
    {
        $encrypted = $this->encryptionService->isEncrypted($this->insight->getAgent()->getKeyName());
        $loopInfos = $this->mountLoopHelper->attachLoopDevices(
            $this->insight->getAgent()->getKeyName(),
            $this->getCloneSpec($snapshotEpoch)->getTargetMountpoint(),
            $encrypted
        );

        return $loopInfos;
    }

    /**
     * @param int $snapshotEpoch
     * @param LoopInfo[] $loopInfos
     */
    private function mountDevicesForSingleSnapshot(int $snapshotEpoch, array $loopInfos)
    {
        $this->writeStatus(InsightStatus::STATUS_MOUNTING);
        $agent = $this->insight->getAgent();
        $reFsVols = $this->insightsResultsService->getReFsVols(
            $agent,
            $this->insight->getFirstPoint(),
            $this->insight->getSecondPoint()
        );
        foreach ($loopInfos as $volGuid => $loopInfo) {
            if (in_array($volGuid, $reFsVols, true)) {
                continue; // ignore unsupported FS
            }
            $path = $loopInfo->getPathToPartition(1);

            $this->partProbe($path);

            $name = $agent->getKeyName();
            $mount = sprintf(static::MOUNT_POINT_FORMAT, $name, $snapshotEpoch, $volGuid);

            if (!$this->filesystem->exists($mount)) {
                $this->filesystem->mkdir($mount, true, self::MKDIR_MODE);
            }

            $mountResult = $this->mountManager->mountDevice($path, $mount);

            if ($mountResult->getExitCode() !== 0) {
                throw new MountException($path, $mount, $mountResult->getMountOutput());
            }
        }
    }

    /**
     * @param string $devicePath
     */
    private function partProbe(string $devicePath)
    {
        $process = $this->processFactory->get(["partprobe", $devicePath]);

        $process->run();
    }
}
