<?php

namespace Datto\Restore\Insight\InsightStages;

use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Asset\AssetInfoService;
use Datto\Config\AgentShmConfigFactory;
use Datto\Restore\Insight\BackupInsight;
use Datto\Restore\Insight\InsightStatus;
use Datto\System\Transaction\Stage;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Shared functions and members for backup insights stages
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
abstract class InsightStage implements Stage
{
    const MFT_CLONE_EXTENSION = 'mft';
    /** @var BackupInsight */
    protected $insight;

    /** @var AssetCloneManager */
    protected $cloneManager;

    /** @var Filesystem */
    protected $filesystem;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /** @var AgentShmConfigFactory */
    protected $agentShmConfigFactory;

    public function __construct(
        BackupInsight $insight,
        AssetCloneManager $cloneManager,
        Filesystem $filesystem,
        AgentShmConfigFactory $agentShmConfigFactory,
        DeviceLoggerInterface $logger
    ) {
        $this->insight = $insight;
        $this->cloneManager = $cloneManager;
        $this->filesystem = $filesystem;
        $this->agentShmConfigFactory = $agentShmConfigFactory;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function setContext($context)
    {
        // not yet implemented
    }

    /**
     * Write status to a file that the UI reads from
     *
     * @param string $message
     * @param bool $completed
     * @param bool $failed
     */
    protected function writeStatus(string $message, bool $completed = false, bool $failed = false)
    {
        $keyName = $this->insight->getAgent()->getKeyName();

        $agentShmConfig = $this->agentShmConfigFactory->create($keyName);

        $status = new InsightStatus();
        $status->setMessage($message);
        $status->setAgentKey($keyName);
        $status->setCompleted($completed);
        $status->setFailed($failed);

        $agentShmConfig->saveRecord($status);

        if ($completed) {
            $this->flagComplete();
        }

        $this->filesystem->filePutContents($this->getDumpFile(), json_encode($this->insight->toArray()));
    }

    /**
     * Returns the clone spec for the zfs clone of a given snapshot
     *
     * @param $snapshot
     * @return CloneSpec
     */
    protected function getCloneSpec(int $snapshot): CloneSpec
    {
        return CloneSpec::fromAsset($this->insight->getAgent(), $snapshot, CloneSnapshotStage::MFT_CLONE_EXTENSION);
    }


    /**
     * @return string
     */
    protected function getDumpFile(): string
    {
        $keyName = $this->insight->getAgent()->getKeyName();
        return AssetInfoService::KEY_BASE . "$keyName.snapCompare";
    }

    private function flagComplete()
    {
        $this->insight->setComplete(true);
    }
}
