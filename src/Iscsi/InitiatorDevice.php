<?php

namespace Datto\Iscsi;

/**
 * Class that represents an Initiator Device
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class InitiatorDevice
{
    /* @var string */
    private $targetName;

    /* @var string */
    private $legacyName;

    /* @var string */
    private $sessionId;

    /**
     * @param string $targetName
     * @param string $legacyName
     * @param string $sessionId
     */
    public function __construct(string $targetName, string $legacyName, string $sessionId)
    {
        $this->targetName = $targetName;
        $this->legacyName = $legacyName;
        $this->sessionId = $sessionId;
    }

    /**
     * @return string
     */
    public function getLegacyName(): string
    {
        return $this->legacyName;
    }

    /**
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * @return string
     */
    public function getTargetName(): string
    {
        return $this->targetName;
    }
}
