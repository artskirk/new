<?php
namespace Datto\Asset\Agent\PrePostScripts;

/**
 * A script that can be run before and after taking a snapshot, to avoid data loss
 * (These scripts typically relate to the databases running on the protected machine.)
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class PrePostScript
{
    /** @var string */
    private $name;

    /** @var string */
    private $displayName;

    /** @var boolean */
    private $enabled;

    /** @var int */
    private $timeout;

    public function __construct($name, $displayName, $enabled, $timeout)
    {
        $this->name = $name;
        $this->displayName = $displayName;
        $this->enabled = $enabled;
        $this->timeout = $timeout;
    }

    /**
     * @return string script name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string script title, displayed in the UI
     */
    public function getDisplayName()
    {
        return $this->displayName;
    }

    /**
     * @return boolean whether to run the script
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @param boolean $enabled whether to run the script
     */
    public function setEnabled($enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * @return int timeout when running the script
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * Overwrites some of this object's settings with those of $from
     * (Used when updating the local PrePostScript volumes to match the agent API)
     *
     * @param PrePostScript $from a script object to copy some settings from
     */
    public function copyFrom(PrePostScript $from): void
    {
        $this->name = $from->getName();
        $this->displayName = $from->getDisplayName();
        $this->enabled = ($this->isEnabled() || $from->isEnabled());
        if ($this->timeout === 0) {
            $this->timeout = $from->getTimeout();
        }
    }
}
