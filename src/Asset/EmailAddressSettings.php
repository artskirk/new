<?php

namespace Datto\Asset;

/**
 * Settings for email alerts
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class EmailAddressSettings
{
    /** @var array email addresses for critical alert emails  */
    private $critical;

    /** @var array email addresses for log digest emails */
    private $log;

    /** @var array email addresses for screenshot failed emails */
    private $screenshotFailed;

    /** @var array email addresses for screenshot succeeded emails */
    private $screenshotSuccess;
    
    /** @var array email addresses for warning-level error emails */
    private $warning;

    /** @var array email addresses for weekly backup report emails */
    private $weekly;

    /** @var array email addresses for notice emails */
    private $notice;

    /**
     * @param array $critical email addresses for critical alert emails
     * @param array $log email addresses for log digest emails
     * @param array $screenshotFailed email addresses for screenshot failed emails
     * @param array $screenshotSuccess email addresses for screenshot succeeded emails
     * @param array $warning email addresses for warning-level error emails
     * @param array $weekly email addresses for weekly backup report emails
     * @param array $notice email addresses for notice emails
     */
    public function __construct(
        array $critical = array(),
        array $log = array(),
        array $screenshotFailed = array(),
        array $screenshotSuccess = array(),
        array $warning = array(),
        array $weekly = array(),
        array $notice = array()
    ) {
        $this->critical = $critical;
        $this->log = $log;
        $this->notice = $notice;
        $this->screenshotFailed = $screenshotFailed;
        $this->screenshotSuccess = $screenshotSuccess;
        $this->warning = $warning;
        $this->weekly = $weekly;
    }

    /**
     * @return array email addresses for critical alert emails
     */
    public function getCritical()
    {
        return $this->critical;
    }

    /**
     * @param array $critical email addresses for critical alert emails
     */
    public function setCritical(array $critical): void
    {
        $this->critical = $critical;
    }

    /**
     * @return array email addresses for warning-level error emails
     */
    public function getWarning()
    {
        return $this->warning;
    }

    /**
     * @param array $warning email addresses for warning-level error emails
     */
    public function setWarning(array $warning): void
    {
        $this->warning = $warning;
    }

    /**
     * @return array email addresses for log digest emails
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * @param array $log email addresses for log digest emails
     */
    public function setLog(array $log): void
    {
        $this->log = $log;
    }

    /**
     * @return array $notice email addresses for notice emails
     */
    public function getNotice()
    {
        return $this->notice;
    }

    /**
     * @param array $notice email addresses for notice
     */
    public function setNotice(array $notice): void
    {
        $this->notice = $notice;
    }

    /**
     * @return array email addresses for screenshot failed emails
     */
    public function getScreenshotFailed()
    {
        return $this->screenshotFailed;
    }

    /**
     * @param array $screenshot email addresses for screenshot failed emails
     */
    public function setScreenshotFailed(array $screenshot): void
    {
        $this->screenshotFailed = $screenshot;
    }

    /**
     * @return array email addresses for screenshot succeeded emails
     */
    public function getScreenshotSuccess()
    {
        return $this->screenshotSuccess;
    }

    /**
     * @param array $screenshotSuccess email addresses for screenshot succeeded emails
     */
    public function setScreenshotSuccess(array $screenshotSuccess): void
    {
        $this->screenshotSuccess = $screenshotSuccess;
    }

    /**
     * @return array email addresses for weekly backup report emails
     */
    public function getWeekly()
    {
        return $this->weekly;
    }

    /**
     * @param array $weekly email addresses for weekly backup report emails
     */
    public function setWeekly(array $weekly): void
    {
        $this->weekly = $weekly;
    }

    /**
     * @param EmailAddressSettings $from Another asset's EmailAddressSettings object, to be copied
     */
    public function copyFrom(EmailAddressSettings $from): void
    {
        $this->setCritical($from->getCritical());
        $this->setLog($from->getLog());
        $this->setScreenshotFailed($from->getScreenshotFailed());
        $this->setScreenshotSuccess($from->getScreenshotSuccess());
        $this->setWarning($from->getWarning());
        $this->setWeekly($from->getWeekly());
    }
}
