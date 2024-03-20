<?php

namespace Datto\Filesystem\Resize;

/**
 * Represents status of a resize job.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ResizeProgress
{
    /** @var bool */
    private $running;

    /** @var string */
    private $stage;

    /** @var string */
    private $percent;

    /** @var string|null */
    private $stdError;

    /**
     * ResizeProgress constructor.
     * @param bool $running
     * @param string $stage
     * @param double $percent
     * @param string|null $stdError
     */
    public function __construct(
        $running,
        $stage,
        $percent,
        $stdError
    ) {
        $this->running = $running;
        $this->stage = $stage;
        $this->percent = $percent;
        $this->stdError = $stdError;
    }

    /**
     * @return bool
     */
    public function isRunning()
    {
        return $this->running;
    }

    /**
     * @return null|string
     */
    public function getStage()
    {
        return $this->stage;
    }

    /**
     * @return null|string
     */
    public function getPercent()
    {
        return $this->percent;
    }

    /**
     * @return null|string
     */
    public function getStdError()
    {
        return $this->stdError;
    }

    /**
     * @return mixed[]
     */
    public function toArray()
    {
        return array(
            'running' => $this->running,
            'stage' => $this->stage,
            'percent' => $this->percent,
            'stdErr' => $this->stdError
        );
    }
}
