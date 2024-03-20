<?php

namespace Datto\Asset\Agent;

use Datto\Config\AgentConfigFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Service for handling the "doDiffMerge" file.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class DiffMergeService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DO_DIFF_MERGE_AGENT_KEY = 'doDiffMerge';
    const MAX_BAD_SCREENSHOT_COUNT_AGENT_KEY = 'diffMergeFailedScreenshots';

    /** default max number of bad screenshots before os volume diffmerge */
    const DEFAULT_MAX_BAD_SCREENSHOT_COUNT = 5;

    /** DWA Platform */
    const PLATFORM_DWA = 'DWA';

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->dateTimeService = $dateTimeService;
    }

    public function setDiffMergeAllVolumes(string $agentKey): void
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $agentConfig->setRaw(self::DO_DIFF_MERGE_AGENT_KEY, '');
    }

    public function clearDoDiffMerge(string $agentKey): void
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $agentConfig->clear(self::DO_DIFF_MERGE_AGENT_KEY);
    }

    /**
     * @param Agent $agent
     * @param string[]|array $volumeGuids
     */
    public function setDiffMergeVolumeGuids(Agent $agent, array $volumeGuids): void
    {
        if (!$agent->isVolumeDiffMergeSupported()) {
            throw new Exception($agent->getName() . ": Agent does not support volume diff merge.");
        }
        $agentConfig = $this->agentConfigFactory->create($agent->getKeyName());
        if ($volumeGuids) {
            $agentConfig->setRaw(self::DO_DIFF_MERGE_AGENT_KEY, json_encode($volumeGuids));
        } else {
            $agentConfig->clear(self::DO_DIFF_MERGE_AGENT_KEY);
        }
    }

    /**
     * @param Agent $agent
     */
    public function setDiffMergeOsVolume(Agent $agent): void
    {
        $osVolumeGuid = $this->getOsVolumeGuid($agent);
        if ($agent->isVolumeDiffMergeSupported()) {
            $this->setDiffMergeVolumeGuids($agent, [$osVolumeGuid]);
        } elseif ($this->canSingleVolumeDiffmerge($agent)) {
            $this->setDiffMergeAllVolumes($agent->getKeyName());
        } else {
            throw new Exception($agent->getName() . ": Agent does not support OS volume diff merge.");
        }
    }

    /**
     * Check if diffmerge needed.
     * After 5 failed screenshot attempts we try a diffmerge.
     * Asset must have succeeded a screenshot in the last month,
     * and if it has not done a diffmerge since its last successful screenshot.
     * Only on DWA systems that support per-volume (future version with linked DWA ticket) OR have only 1 volume.
     * Only perform on OS drive when possible.
     *
     * @param Agent $agent
     * @return boolean
     */
    public function isDiffMergeNeeded(Agent $agent)
    {
        $maxBadScreenshotCount = $this->getMaxBadScreenshotCount($agent->getKeyName());
        if ($maxBadScreenshotCount === 0) {
            return false;
        }

        $osVolumeGuid = $this->getOsVolumeGuid($agent);
        $recoveryPoints = $agent->getLocal()->getRecoveryPoints();
        $mostRecentGoodScreenshot = $recoveryPoints->getMostRecentGoodScreenshot();

        $hasGoodScreenshotInLastMonth = $mostRecentGoodScreenshot !== null &&
            $mostRecentGoodScreenshot->getEpoch() >= $this->dateTimeService->stringToTime('-30 days');
        $hasLastConsecutiveScreenshotsFailed = $recoveryPoints->isLastCountScreenshotsBad($maxBadScreenshotCount);
        $hasDiffmergeSinceLastGoodScreenshot = $recoveryPoints->hasDiffmergeSinceLastGoodScreenshot($osVolumeGuid);
        $hasSupportForPerVolumeDiffmerge = $agent->isVolumeDiffMergeSupported();
        $canSingleVolumeDiffmerge = $this->canSingleVolumeDiffmerge($agent);

        return $hasLastConsecutiveScreenshotsFailed &&
            $hasGoodScreenshotInLastMonth &&
            !$hasDiffmergeSinceLastGoodScreenshot &&
            ($hasSupportForPerVolumeDiffmerge || $canSingleVolumeDiffmerge);
    }

    public function getDiffMergeSettings(string $agentKey): DiffMergeSettings
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $content = $agentConfig->getRaw(self::DO_DIFF_MERGE_AGENT_KEY);
        if ($content === false) {
            $allVolumes = false;
            $volumeGuids = [];
        } else {
            $allVolumes = $content === '' || $content === '1';
            $guids = json_decode($content);
            $volumeGuids = is_array($guids) ? $guids : [];
        }
        return new DiffMergeSettings($allVolumes, $volumeGuids);
    }

    /**
     * Sets the number of consecutive screenshot failures that will
     * trigger an automatic diff merge on the OS volume.
     * A value of 0 disables automatic diff merges.
     *
     * @param string $agentKey
     * @param int $count
     */
    public function setMaxBadScreenshotCount(string $agentKey, int $count)
    {
        $this->logger->info(
            'DMS0100 Setting max bad screenshot count for agent',
            [
                'agentKey' => $agentKey,
                'count' => $count
            ]
        );
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $agentConfig->setRaw(self::MAX_BAD_SCREENSHOT_COUNT_AGENT_KEY, $count);
    }

    /**
     * Gets the number of consecutive screenshot failures that will
     * trigger an automatic diff merge on the OS volume.
     * A value of 0 disables automatic diff merges.
     *
     * @param string $agentKey
     * @return int
     */
    public function getMaxBadScreenshotCount(string $agentKey): int
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $count = $agentConfig->getRaw(self::MAX_BAD_SCREENSHOT_COUNT_AGENT_KEY, self::DEFAULT_MAX_BAD_SCREENSHOT_COUNT);
        $count = is_numeric($count) && $count >= 0 ? intval($count) : self::DEFAULT_MAX_BAD_SCREENSHOT_COUNT;
        $this->logger->debug(
            'DMS0101 Retrieved max bad screenshot count for agent',
            [
                'agentKey' => $agentKey,
                'count' => $count
            ]
        );
        return $count;
    }

    /**
     * Return the Guid for the verificationAsset
     *
     * @param Agent $agent
     * @return string|null the guid of the os volume
     */
    private function getOsVolumeGuid(Agent $agent)
    {
        $osVol = null;
        $guid = null;
        $volumes = $agent->getVolumes();
        foreach ($volumes as $volume) {
            if ($volume->isOsVolume()) {
                if ($guid === null) {
                    $guid = $volume->getGuid();
                    $osVol = $volume;
                } else {
                    $this->logger->info(
                        'DMS0003 Additional OS Volume GUID detected when setting diff-merge for OS volume',
                        [
                            'detectedOsVolume' => [
                                'guid' => $osVol->getGuid(),
                                'mountpoint' => $osVol->getMountpoint()
                            ],
                            'additionalOsVolume' => [
                                'guid' => $volume->getGuid(),
                                'mountpoint' => $volume->getMountpoint()
                            ]
                        ]
                    );
                }
            }
        }
        if ($guid === null) {
            throw new Exception($agent->getName() . ": OS Drive does not exist.");
        }
        return $guid;
    }

    /**
     * For older versions of of the DWA agent that don't support volume diff
     * merge, we can still use the "diff merge all volumes" flag as long as the
     * agent only has a single volume (which must be the OS volume).
     * This function returns true if we can use the "All Volumes" diff merge
     * on an older agent.
     *
     * @param Agent $agent
     * @return true if platform is DWA
     */
    private function canSingleVolumeDiffmerge(Agent $agent)
    {
        return $agent->getPlatform()->getShortName() === self::PLATFORM_DWA &&
               count($agent->getVolumes()) === 1 &&
               !$agent->isRescueAgent();
    }
}
