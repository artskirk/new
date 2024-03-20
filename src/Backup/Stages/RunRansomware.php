<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Malware\RansomwareService;
use Exception;
use Throwable;

/**
 * This backup stage runs ransomware detection.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class RunRansomware extends BackupStage
{
    const RANSOMWARE_CLONE_SUFFIX = 'ransomware';

    /** @var RansomwareService */
    private $ransomwareService;

    public function __construct(RansomwareService $ransomwareService)
    {
        $this->ransomwareService = $ransomwareService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        /** @var Agent $asset */
        $asset = $this->context->getAsset();

        if ($this->context->isExpectRansomwareChecks()) {
            try {
                $results = $this->ransomwareService->runTests(
                    $asset,
                    $this->context->getLocalVerificationDattoImages(),
                    $this->getLatestSnapshot($asset)
                );

                $this->context->setRansomwareResults($results);
            } catch (Throwable $throwable) {
                $this->context->getLogger()->error('MAL0030 Ransomware test failed: ' . $throwable);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * Get the most recent snapshot for the current asset.
     *
     * @param Asset $asset
     * @return int Epoch time of the most recent snapshot
     */
    private function getLatestSnapshot(Asset $asset): int
    {
        $latestRecoveryPoint = $asset->getLocal()->getRecoveryPoints()->getLast();
        if (is_null($latestRecoveryPoint)) {
            throw new Exception("No local snapshots exist for asset {$asset->getKeyName()}");
        }
        return $latestRecoveryPoint->getEpoch();
    }
}
