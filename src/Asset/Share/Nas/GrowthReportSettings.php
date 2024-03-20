<?php

namespace Datto\Asset\Share\Nas;

/**
 * Manages growth report information
 *
 * @author Rixhers Aajzi <rajazi@datto.com>
 */
class GrowthReportSettings
{
    /** @var string */
    protected $emailInterval;

    /** @var string */
    protected $emailList;

    /** @var int */
    protected $lastEmailSentTime;

    /**
     * GrowthReport constructor.
     */
    public function __construct()
    {
        $this->emailInterval = 'never';
        $this->emailList = '';
    }

    /**
     * @return string
     */
    public function getEmailList()
    {
        return $this->emailList;
    }

    /**
     * @param string $emails
     */
    public function setEmailList($emails): void
    {
        $this->emailList = $emails;
    }

    /**
     * @return string
     */
    public function getFrequency()
    {
        return $this->emailInterval;
    }

    /**
     * @param string $emails
     */
    public function setFrequency($interval): void
    {
        $this->emailInterval = $interval;
    }

    /**
     * @param int $time the time of the last growth report that was sent.
     * @return GrowthReportSettings
     */
    public function setEmailTime($time)
    {
        $this->lastEmailSentTime = $time;
    }

    /**
     * @return int
     */
    public function getEmailTime()
    {
        return $this->lastEmailSentTime;
    }

    /**
     * Copy existing GrowthReportSettings.
     * Note: lastEmailSentTime will not be copied.
     *
     * @param GrowthReportSettings $from Another NAS Share's GrowthReportSettings to copy.
     */
    public function copyFrom(GrowthReportSettings $from): void
    {
        $this->setEmailList($from->getEmailList());
        $this->setFrequency($from->getFrequency());
    }
}
