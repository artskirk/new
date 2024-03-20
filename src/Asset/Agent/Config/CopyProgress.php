<?php

namespace Datto\Asset\Agent\Config;

use Datto\Config\JsonConfigRecord;

/**
 * Config record representing agent config copy progress
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class CopyProgress extends JsonConfigRecord
{
    /** @var int */
    private $percentage;

    /** @var string */
    private $source;

    /** @var bool */
    private $failed;

    /**
     * @param int $percentage
     * @param string $source
     * @param bool $failed
     */
    public function __construct(int $percentage = 0, string $source = '', bool $failed = false)
    {
        $this->percentage = $percentage;
        $this->source = $source;
        $this->failed = $failed;
    }

    /**
     * @inheritdoc
     */
    public function getKeyName(): string
    {
        return 'copyProgress';
    }

    /**
     * @inheritdoc
     */
    protected function load(array $vals)
    {
        $this->percentage = $vals['percentage'] ?? 0;
        $this->source = $vals['source'] ?? '';
        $this->failed = $vals['failed'] ?? false;
    }

    /**
     * @return int
     */
    public function getPercentage(): int
    {
        return $this->percentage;
    }

    /**
     * @param int $percentage
     */
    public function setPercentage(int $percentage): void
    {
        $this->percentage = $percentage;
    }

    /**
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @param string $source
     */
    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    /**
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->failed;
    }

    /**
     * @param bool $failed
     */
    public function setFailed(bool $failed): void
    {
        $this->failed = $failed;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return ['percentage' => $this->percentage, 'source' => $this->source, 'failed' => $this->failed];
    }
}
