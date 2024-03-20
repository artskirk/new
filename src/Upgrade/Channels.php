<?php

namespace Datto\Upgrade;

/**
 * Holds the currently selected channel and available channels.
 *
 * @author Peter Salu <psalu@datto.com>
 */
class Channels
{
    /** @var string */
    private $selected;

    /** @var array */
    private $available;

    /**
     * @param $selected
     * @param array $available
     */
    public function __construct($selected, array $available)
    {
        $this->selected = $selected;
        $this->available = $available;
    }

    /**
     * @return string
     */
    public function getSelected()
    {
        return $this->selected;
    }

    /**
     * @return array list of all available channels, also including the selected channel (even if it is not available)
     */
    public function getAll()
    {
        $channels = $this->available;
        $selectedIsAvailable = count(preg_grep("/^$this->selected$/i", $this->available)) > 0;
        if (!$selectedIsAvailable) {
            $channels[] = $this->selected;
        }
        return $channels;
    }

    /**
     * @return array
     */
    public function getAvailable()
    {
        return $this->available;
    }

    /**
     * @param string $selected
     */
    public function setSelected($selected)
    {
        $this->selected = $selected;
    }

    /**
     * @param array $available
     */
    public function setAvailable(array $available)
    {
        $this->available = $available;
    }
}
