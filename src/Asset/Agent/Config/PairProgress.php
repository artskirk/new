<?php

namespace Datto\Asset\Agent\Config;

use Datto\Core\Configuration\ConfigRecordInterface;

/**
 * Config record representing agent pairing progress
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class PairProgress implements ConfigRecordInterface
{
    /**
     * Add agent status codes.
     */
    const CODE_CREATE_SUCCESS = 'ADD_AGENT_SUCCESS';
    const CODE_CREATE_FAIL = 'ADD_AGENT_FAIL';
    const CODE_CREATE_EXISTS = 'ADD_AGENT_EXISTS';
    const CODE_CREATE_INIT = 'ADD_AGENT_INITIALIZING';
    const CODE_CREATE_CREATING = 'ADD_AGENT_CREATING';
    const CODE_CREATE_CANCEL = 'ADD_AGENT_CANCEL';

    /** @var int */
    private $progress;
    /** @var string */
    private $status;
    /** @var string  */
    private $code;

    /**
     * @param int $progress
     * @param string $status
     */
    public function __construct(int $progress = 0, string $status = '')
    {
        $this->setProgress($progress);
        $this->status = $status;
    }

    /**
     * @inheritdoc
     */
    public function getKeyName(): string
    {
        return 'addProgress';
    }

    /**
     * @inheritdoc
     */
    public function unserialize(string $raw)
    {
        $vals = explode(',', $raw);

        //if the content is deformed... ie there is no comma to split on separating the progress from status,
        // create it on our own
        if (count($vals) < 2) {
            $vals = array(0, 'Initializing');
        }

        //get some info from the raw progress content
        $this->setProgress((int) ($vals[0]));
        $this->status = (string) $vals[1];
    }

    /**
     * @inheritdoc
     */
    public function serialize(): string
    {
        return $this->getProgress() . ',' . $this->getStatus();
    }

    /**
     * @return int
     */
    public function getProgress(): int
    {
        return $this->progress;
    }

    /**
     * @param int $progress
     */
    public function setProgress(int $progress): void
    {
        switch ($progress) {
            case -2:
                $this->code = self::CODE_CREATE_CANCEL;
                break;
            case -1:
                $this->code = self::CODE_CREATE_FAIL;
                break;
            case 0:
                $this->code = self::CODE_CREATE_INIT;
                break;
            case 100:
                $this->code = self::CODE_CREATE_SUCCESS;
                break;
            default:
                $this->code = self::CODE_CREATE_CREATING;
                break;
        }
        $this->progress = $progress;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }
}
