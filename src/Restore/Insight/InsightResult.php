<?php

namespace Datto\Restore\Insight;

use Datto\Asset\Agent\Agent;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Represents all volume results for a backup insight for the agent
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class InsightResult implements \JsonSerializable
{
    /** @var Agent */
    private $agent;

    /** @var int */
    private $pointOne;

    /** @var int */
    private $pointTwo;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var VolumeInsightResult[] */
    private $results = [];

    /**
     * @param Agent $agent
     * @param int $pointOne
     * @param int $pointTwo
     * @param Filesystem $filesystem
     * @param DeviceLoggerInterface|null $logger
     */
    public function __construct(
        Agent $agent,
        int $pointOne,
        int $pointTwo,
        Filesystem $filesystem,
        DeviceLoggerInterface $logger = null
    ) {
        $this->agent = $agent;
        $this->pointOne = $pointOne;
        $this->pointTwo = $pointTwo;
        $this->filesystem = $filesystem;
        $this->logger = $logger ?: LoggerFactory::getAssetLogger($this->agent->getKeyName());
    }

    /**
     * @return VolumeInsightResult[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Pull results from disk
     */
    public function loadResults()
    {
        $volumes = $this->agent->getVolumes();
        foreach ($volumes as $volume) {
            try {
                $result = new VolumeInsightResult($this->agent->getKeyName(), $volume, $this->pointOne, $this->pointTwo, $this->filesystem);
                $result->loadResults();

                $this->results[] = $result;
            } catch (\Throwable $e) {
                // It's possible not all volumes were compared if there was a drive added/removed between snapshots
                $this->logger->warning('INR0001 Could not load insight results', ['exception' => $e]);
            }
        }
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $resultsArray = [];
        foreach ($this->results as $result) {
            $resultsArray[] = $result->toArray();
        }

        return $resultsArray;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
