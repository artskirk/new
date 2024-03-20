<?php

namespace Datto\Restore\Insight;

use Datto\Asset\Agent\Volume;
use Datto\Asset\AssetInfoService;
use Datto\Common\Utility\Filesystem;

/**
 * Represents a backup insights results for a single volume
 *
 * Actual results are not loaded until explicitly needed as they can be massive.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class VolumeInsightResult implements \JsonSerializable
{
    const RESULTS_FILE_FORMAT = "%s-%s-%s-%s.mftDiff";

    const STATE_CREATED = 'created';
    const STATE_MODIFIED = 'modified';
    const STATE_DELETED = 'deleted';

    /** @var Volume */
    private $volume;

    /** @var int */
    private $firstPoint;

    /** @var int */
    private $secondPoint;

    /** @var Filesystem */
    private $filesystem;

    /** @var string */
    private $agentKey;

    /** @var array|null */
    private $results = null;

    /**
     * @param string $agentKey
     * @param Volume $volume
     * @param int $firstPoint
     * @param int $secondPoint
     * @param Filesystem $filesystem
     */
    public function __construct(
        string $agentKey,
        Volume $volume,
        int $firstPoint,
        int $secondPoint,
        Filesystem $filesystem
    ) {
        $this->agentKey = $agentKey;
        $this->volume = $volume;
        $this->firstPoint = $firstPoint;
        $this->secondPoint = $secondPoint;
        $this->filesystem = $filesystem;
    }

    /**
     * @return array|null
     */
    public function getResults()
    {
        return $this->results;
    }

    /**
     * @param array $results
     */
    public function setResults(array $results)
    {
        $this->results = $results;
    }

    /**
     * @return Volume
     */
    public function getVolume(): Volume
    {
        return $this->volume;
    }

    /**
     * Pull results from disk
     */
    public function loadResults()
    {
        $file = $this->getDiffFile();
        if (!$this->filesystem->exists($file)) {
            throw new \Exception("Unable to load insight results for " . $this->volume->getGuid() . ":" . $this->firstPoint . "-" . $this->secondPoint);
        }

        $this->results = json_decode(trim($this->filesystem->fileGetContents($file)), true);
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $volumeKey = $this->volume->getMountpoint();

        return [$volumeKey => $this->results];
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * @return string
     */
    public function getDiffFile(): string
    {
        return AssetInfoService::KEY_BASE . sprintf(static::RESULTS_FILE_FORMAT, $this->agentKey, $this->volume->getGuid(), $this->firstPoint, $this->secondPoint);
    }
}
