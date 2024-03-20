<?php

namespace Datto\System;

/**
 * Class to represent system health data
 *
 * @author Marcus Recck <mr@datto.com>
 */
class Health implements \JsonSerializable
{
    const SCORE_OK = 1;
    const SCORE_DEGRADED = 0;
    const SCORE_DOWN = -1;

    const SCORE_READABLE_OK = 'OK';
    const SCORE_READABLE_DEGRADED = 'DEGRADED';
    const SCORE_READABLE_DOWN = 'DOWN';

    const SCORE_MAP = [
        self::SCORE_OK => self::SCORE_READABLE_OK,
        self::SCORE_DEGRADED => self::SCORE_READABLE_DEGRADED,
        self::SCORE_DOWN => self::SCORE_READABLE_DOWN
    ];

    /** @var int */
    private $zpoolHealthScore;

    /** @var int */
    private $memoryHealthScore;

    /** @var int */
    private $cpuHealthScore;

    /** @var int */
    private $iopsHealthScore;

    public function __construct(
        int $zpoolHealthScore,
        int $memoryHealthScore,
        int $cpuHealthScore,
        int $iopsHealthScore
    ) {
        $this->zpoolHealthScore = $zpoolHealthScore;
        $this->memoryHealthScore = $memoryHealthScore;
        $this->cpuHealthScore = $cpuHealthScore;
        $this->iopsHealthScore = $iopsHealthScore;
    }

    /**
     * @return int
     */
    public function getZpoolHealthScore(): int
    {
        return $this->zpoolHealthScore;
    }

    /**
     * @param int $zpoolHealthScore
     */
    public function setZpoolHealthScore(int $zpoolHealthScore)
    {
        $this->zpoolHealthScore = $zpoolHealthScore;
    }

    /**
     * @return int
     */
    public function getMemoryHealthScore(): int
    {
        return $this->memoryHealthScore;
    }

    /**
     * @param int $memoryHealthScore
     */
    public function setMemoryHealthScore(int $memoryHealthScore)
    {
        $this->memoryHealthScore = $memoryHealthScore;
    }

    /**
     * @return int
     */
    public function getCpuHealthScore(): int
    {
        return $this->cpuHealthScore;
    }

    /**
     * @param int $cpuHealthScore
     */
    public function setCpuHealthScore(int $cpuHealthScore)
    {
        $this->cpuHealthScore = $cpuHealthScore;
    }

    /**
     * @return int
     */
    public function getIopsHealthScore(): int
    {
        return $this->iopsHealthScore;
    }

    /**
     * @param int $iopsHealthScore
     */
    public function setIopsHealthScore(int $iopsHealthScore)
    {
        $this->iopsHealthScore = $iopsHealthScore;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'zpool' => self::SCORE_MAP[$this->getZpoolHealthScore()],
            'memory' => self::SCORE_MAP[$this->getMemoryHealthScore()],
            'cpu' => self::SCORE_MAP[$this->getCpuHealthScore()],
            'iops' => self::SCORE_MAP[$this->getIopsHealthScore()]
        ];
    }
}
