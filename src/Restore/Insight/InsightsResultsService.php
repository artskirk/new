<?php

namespace Datto\Restore\Insight;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Volume;
use Datto\Asset\AssetInfoService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\CloneSpec;

/**
 * Creates insight results
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class InsightsResultsService
{
    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem(new ProcessFactory());
    }

    /**
     * @param Agent $agent
     * @param int $pointOne
     * @param int $pointTwo
     * @return InsightResult
     */
    public function createResults(Agent $agent, int $pointOne, int $pointTwo): InsightResult
    {
        return new InsightResult($agent, $pointOne, $pointTwo, $this->filesystem);
    }

    /**
     * @param string $agentKey
     * @param Volume $volume
     * @param int $pointOne
     * @param int $pointTwo
     * @return VolumeInsightResult
     */
    public function createVolumeResult(string $agentKey, Volume $volume, int $pointOne, int $pointTwo): VolumeInsightResult
    {
        return new VolumeInsightResult($agentKey, $volume, $pointOne, $pointTwo, $this->filesystem);
    }

    /**
     * @param string $agentKey
     * @return string[]
     */
    public function getResultsFiles(string $agentKey): array
    {
        $allDiffFilesForAgentGlob = sprintf(VolumeInsightResult::RESULTS_FILE_FORMAT, $agentKey, "*", "*", "*");

        $allDiffFilesForAgentGlob = AssetInfoService::KEY_BASE . $allDiffFilesForAgentGlob;

        return $this->filesystem->glob($allDiffFilesForAgentGlob);
    }

    /**
     * find all the ReFS volumes in both backup points
     *
     * @param Agent $agent
     * @param int $firstPoint
     * @param int $secondPoint
     * @return array
     */
    public function getReFsVols(Agent $agent, int $firstPoint, int $secondPoint): array
    {
        $reFsVols1 = $this->getReFsVolsForOnePoint($agent, $firstPoint);
        $reFsVols2 = $this->getReFsVolsForOnePoint($agent, $secondPoint);
        $reFsVols = array_merge($reFsVols1, $reFsVols2);
        return array_unique($reFsVols);
    }

    /**
     * find all the ReFS volumes in a specific backup point
     *
     * @param Agent $agent
     * @param int $point
     * @return array
     */
    private function getReFsVolsForOnePoint(Agent $agent, int $point): array
    {
        $reFsVolsForOnePoint = [];
        $cloneSpec = $cloneSpec = CloneSpec::fromAsset($agent, $point, InsightsService::SUFFIX_MFT);
        $info = unserialize($this->filesystem->fileGetContents($cloneSpec->getTargetMountpoint() . '/' . $agent->getKeyName() . '.agentInfo'), ['allowed_classes' => false]);
        $volumes = $info['volumes'];
        foreach ($volumes as $mountpoint => $volume) {
            if (isset($volume['filesystem']) && $volume['filesystem'] === Volume::FILESYSTEM_REFS) {
                $reFsVolsForOnePoint[] = $volume['guid'];
            }
        }
        return $reFsVolsForOnePoint;
    }
}
