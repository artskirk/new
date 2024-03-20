<?php

namespace Datto\System\Update;

/**
 * Encapsulates an hour-based update window.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class HourlyUpdateWindow
{
    /** @var int */
    private $startHour;

    /** @var int */
    private $endHour;

    /**
     * @param int $startHour
     * @param int $endHour
     */
    public function __construct($startHour, $endHour)
    {
        $this->startHour = $startHour;
        $this->endHour = $endHour;
    }

    /**
     * @return int
     */
    public function getStartHour()
    {
        return $this->startHour;
    }

    /**
     * @return int
     */
    public function getEndHour()
    {
        return $this->endHour;
    }
}
