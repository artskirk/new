<?php

namespace Datto\Verification\Screenshot;

use DateTimeZone;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\VerificationSchedule;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\Common\Utility\Filesystem;
use Datto\Asset\Agent\Agent;
use Datto\Config\AgentConfig;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Datto\Log\DeviceLoggerInterface;

/**
 * Service to check if a screenshot has taken place and send an alert/email if needed
 *
 * @author Mike Micatka <mmicatka@datto.com>
 */
class ScreenshotAlertService
{
    const DISABLE_SCREENSHOTS_KEY = 'disableScreenshots';

    /** This is the time between emails, currently set as 1 day */
    const HOURS_TO_SEND_EMAIL = 24;

    const DAYS_PER_WEEK = 7;
    const HOURS_PER_DAY = 24;
    const SECONDS_PER_HOUR = 3600;
    const SCREENSHOTS_FILE_PATTERN = '/datto/config/screenshots/%s.screenshot.*.jpg';

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DateTimeZoneService */
    private $dateTimeZoneService;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var AgentService */
    private $agentService;

    /**
     * @param DateTimeService|null $dateTimeService
     * @param DateTimeZoneService|null $dateTimeZoneService
     * @param Filesystem|null $filesystem
     * @param DeviceConfig|null $deviceConfig
     * @param AgentService|null $agentService
     */
    public function __construct(
        DateTimeService $dateTimeService = null,
        DateTimeZoneService $dateTimeZoneService = null,
        Filesystem $filesystem = null,
        DeviceConfig $deviceConfig = null,
        AgentService $agentService = null
    ) {
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
        $this->dateTimeZoneService = $dateTimeZoneService ?: new DateTimeZoneService();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->agentService = $agentService ?: new AgentService();
    }

    /**
     * An alert/email will be sent if ALL of the following conditions are met:
     *
     * 1. Screenshots are enabled by:
     *  a. Screenshots are supported by the agent
     *  b. There is at least one email set for Screenshot problems
     *  c. Screenshots (and the corresponding alerts) are enabled
     *
     * 2. A backup has successfully occurred since the last screenshot.
     *    If not, there would not be anything new to screenshot
     *
     * 3. A missed scheduled screenshot was detected. We can tell from the schedule that a screenshot was
     *    supposed to occur but did NOT.
     *
     * 4. It has been X hours since we missed that scheduled screenshot. Where X is the error threshold set
     *    by the user in the UI
     *
     * 5. An error email has not already been sent in the past 24 hours.
     *
     * @param Agent $agent
     * @param AgentConfig|null $agentConfig
     * @param DeviceLoggerInterface|null $assetLogger
     */
    public function checkScreenshotTimeByAgent(
        Agent $agent,
        AgentConfig $agentConfig = null,
        DeviceLoggerInterface $assetLogger = null
    ) {
        $assetLogger = $assetLogger ?: LoggerFactory::getAssetLogger($agent->getKeyName());

        $isAgentReplicated = $agent->getOriginDevice()->isReplicated();
        if ($isAgentReplicated) {
            $assetLogger->info('SAS0010 Agent is replicated from another device, skipping agent ' . $agent->getPairName());
            return;
        }

        // This should be removed once we have all of the correct key files attached to the agent
        $agentConfig = $agentConfig ?: new AgentConfig($agent->getKeyName());

        $assetLogger->info('SAS0001 checking agent: ' . $agent->getPairName());

        $screenshotsNotSupported = !$agent->getScreenshot()->isSupported();
        if ($screenshotsNotSupported) {
            $assetLogger->info('SAS0002 screenshots not supported, skipping agent ' . $agent->getPairName());
            return;
        }

        $isAgentPaused = $agent->getLocal()->isPaused();
        if ($isAgentPaused) {
            $assetLogger->info('SAS0009 agent is paused, skipping agent ' . $agent->getPairName());
            return;
        }

        $isAgentArchived = $agent->getLocal()->isArchived();
        if ($isAgentArchived) {
            $assetLogger->info('SAS0010 Agent is archived, skipping agent ' . $agent->getPairName());
            return;
        }

        $emailNotSet = !$this->hasScreenshotEmailAddress($agent);
        if ($emailNotSet) {
            $assetLogger->info(
                'SAS0003 screenshot failures have no email set, skipping agent ' . $agent->getPairName()
            );
            return;
        }

        $screenshotsDisabled =
            (int)$this->deviceConfig->get(self::DISABLE_SCREENSHOTS_KEY) === 1 ||
            $agent->getVerificationSchedule()->getScheduleOption() === VerificationSchedule::NEVER ||
            (int)$agent->getScreenshotVerification()->getErrorTime() === 0;

        if ($screenshotsDisabled) {
            $assetLogger->info('SAS0004 screenshots are disabled, skipping agent ' . $agent->getPairName());
            return;
        }

        try {
            if ($this->verifyScreenshotCompletedAsExpected($agent)) {
                $assetLogger->info('SAS0005 Screenshot completed as expected. ' . $agent->getPairName());
                return;
            }
        } catch (\Exception $e) {
            $assetLogger->info(sprintf(
                'SAS0006 An exception occurred for %s: %s',
                $agent->getPairName(),
                $e->getMessage()
            ));
            return;
        }

        $hoursAllowedWithoutScreenshot = $agent->getScreenshotVerification()->getErrorTime();
        $emailNotSentWithinThreshold = $this->emailNotSent($agentConfig);

        if ($emailNotSentWithinThreshold) {
            // This triggers an email
            $assetLogger->critical(
                'SCR0909 Error! Agent: ' . $agent->getPairName() .
                ' has not taken a successful screenshot in over ' . $hoursAllowedWithoutScreenshot .
                ' hour(s) after the last expected screenshot. Please contact tech support.'
            );

            $agentConfig->set('failedScreenshotEmail', $this->dateTimeService->getTime());
        } else {
            $assetLogger->info(
                'SAS0008 Agent has not taken a screenshot in over ' . $hoursAllowedWithoutScreenshot .
                ' hour(s) after the last expected screenshot ' .
                'but an email has been sent within the last ' . self::HOURS_TO_SEND_EMAIL . ' hours'
            );
        }
    }

    /**
     * Check to see if the latest screenshot taken is within our expected screenshot threshold.
     *
     * @param Agent $agent
     * @return bool
     */
    private function verifyScreenshotCompletedAsExpected(Agent $agent)
    {
        $lastActualScreenshotHour = $this->getLastScreenshotHour($agent);
        $threshold = $agent->getScreenshotVerification()->getErrorTime();
        $weeklySchedule = $this->getWeeklyScreenshotSchedule($agent);

        $nextExpectedScreenshotTimeAfterSuccess = $this->getNextExpectedScreenshotTimeAfterSuccess(
            $weeklySchedule,
            $lastActualScreenshotHour
        );

        // The time between the now and the last expected screenshot. If this value is negative
        // the next expected screenshot is in the future which means everything is behaving appropriately
        $timeSinceLastExpectedScreenshot = $this->dateTimeService->getTime() - $nextExpectedScreenshotTimeAfterSuccess;

        // If the next expected screenshot is the same as the last actual screenshot the screenshot
        // is properly currently being processed (takes place within the same hour time frame)
        $lastExpectedEqualsLastActual = $nextExpectedScreenshotTimeAfterSuccess == $lastActualScreenshotHour;

        $lastExpectedWithinThreshold = $timeSinceLastExpectedScreenshot <= ($threshold * self::SECONDS_PER_HOUR);

        // The last expected screenshot was the last actual screenshot which is good OR
        // the last expectedScreenshot is within the wait threshold so we wait
        $screenshotCompletedAsExpected = $lastExpectedEqualsLastActual || $lastExpectedWithinThreshold;

        return $screenshotCompletedAsExpected;
    }

    /**
     * Check if an agent has at least 1 screenshot-failed email set
     *
     * @param Agent $agent
     * @return bool
     */
    private function hasScreenshotEmailAddress(Agent $agent)
    {
        $emailAddresses = $agent->getEmailAddresses()->getScreenshotFailed();

        if (is_array($emailAddresses)) {
            foreach ($emailAddresses as $emailAddress) {
                if (!empty($emailAddress)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns an appropriate screenshot verification schedule for the specified agent. This depends on which
     * schedule option the agent has selected.
     *
     * @param Agent $agent
     * @return WeeklySchedule
     */
    private function getWeeklyScreenshotSchedule(Agent $agent)
    {
        $scheduleOption = $agent->getVerificationSchedule()->getScheduleOption();

        switch ($scheduleOption) {
            case VerificationSchedule::FIRST_POINT:
                $weeklySchedule = $agent
                    ->getLocal()
                    ->getSchedule()
                    ->filterByFirstPointOfDay();
                break;

            case VerificationSchedule::LAST_POINT:
                $weeklySchedule = $agent
                    ->getLocal()
                    ->getSchedule()
                    ->filterByLastPointOfDay();
                break;

            case VerificationSchedule::CUSTOM_SCHEDULE:
                $weeklySchedule = $agent
                    ->getVerificationSchedule()
                    ->getCustomSchedule();
                break;

            default:
                throw new \Exception('Unexpected screenshot verification schedule option');
        }

        return $weeklySchedule;
    }

    /**
     * Finds the UnixTimeStamp (rounded down to the nearest hour) of the last actual screenshot that has occurred
     *
     * @param Agent $agent
     * @return bool|string
     */
    private function getLastScreenshotHour(Agent $agent)
    {
        // We need to get the list of screenshot files (jpgs) from the device
        // These contain timestamps of when a screenshot was successfully run (but not necessarily verified)
        $globPattern = sprintf(self::SCREENSHOTS_FILE_PATTERN, $agent->getKeyName());
        $screenshots = $this->filesystem->glob($globPattern);

        // Checks if a screenshot has ever been taken
        $noScreenshotsEverTaken = empty($screenshots);

        if ($noScreenshotsEverTaken) {
            // Set the "last" screenshot hour as the time the agent was added
            return intval($agent->getDateAdded());
        }

        // Sort screenshots so the most recent is first
        rsort($screenshots);

        // Split apart the array of screenshot files in order to get the timestamp
        $lastScreenshotTimeStringArray = explode('.', basename($screenshots[0], '.jpg'));
        $lastScreenshotTime = intval(array_pop($lastScreenshotTimeStringArray));

        // This rounds down the timestamp of the screenshot (from the jpg) to the start of the last hour
        $lastScreenshotHour = $lastScreenshotTime - ($lastScreenshotTime % self::SECONDS_PER_HOUR);

        return $lastScreenshotHour;
    }

    /**
     * Gets the next expected screenshot time (as a UnixTimeStamp) after a certain time based on the
     * weekly schedule
     *
     * The weekly schedule is looked at from a start point (the last time of success) in order to find the
     * time (UnixTimeStamp) of the next scheduled verification. This will allow us to determine if a
     * screenshot has been missed
     *
     * The loop starts on the current hour and day and looks ahead one hour at a time until a verification
     * time is seen in the schedule.
     *
     * @param WeeklySchedule $schedule
     * @param $lastSuccessTime
     * @return int
     */
    private function getNextExpectedScreenshotTimeAfterSuccess(WeeklySchedule $schedule, $lastSuccessTime)
    {
        $timezone = new DateTimeZone($this->dateTimeZoneService->getTimeZone());
        $lastSuccessDateTime = date_create_from_format('U', $lastSuccessTime);
        $lastSuccessDateTime->setTimezone($timezone);
        $lastSuccessWeekDay = intval($lastSuccessDateTime->format('w'));
        $lastSuccessHour = intval($lastSuccessDateTime->format('G'));

        $hourCounter = 0;

        // We have to check "8" days in order to get today after current time and also before
        for ($day = 0; $day <= self::DAYS_PER_WEEK; $day++) {
            // This finds the correct weekday for checking our schedule
            $weekDay = ($day + $lastSuccessWeekDay) % self::DAYS_PER_WEEK;
            $daySchedule = $schedule->getDay($weekDay);

            // Set our default variables for checking hours of the day
            $startHour = 0;
            $endHour = self::HOURS_PER_DAY;

            $today = $weekDay === $lastSuccessWeekDay;

            // If we are looking at "today" we need to count up from the last success time, not from midnight
            if ($today) {
                if ($hourCounter < self::HOURS_PER_DAY) {
                    $startHour = $lastSuccessHour + 1;
                    // we had to add an hour so need to account for it
                    $hourCounter++;
                } else {
                    /*
                     * If we are looking at "today" but the hourCounter is greater than 24 we have
                     * looped back around the week, this means we only need to look up until the "current" hour
                     */
                    $endHour = $lastSuccessHour + 1;
                }
            }
            for ($hour = $startHour; $hour < $endHour; $hour++) {
                // This checks to see if a screenshot verification is scheduled for this day and hour
                if ($daySchedule[$hour]) {
                    // Calculates the next expected screenshot (on the hour)
                    $nextExpected = $lastSuccessTime - ($lastSuccessTime % self::SECONDS_PER_HOUR) +
                        $hourCounter * self::SECONDS_PER_HOUR;
                    return $nextExpected;
                }
                /*
                 * Counts "up" the hours until we hit a true value on the schedule,
                 * this allows us to keep a running total of the hours
                 */
                $hourCounter++;
            }
        }

        // We should never make it here or the schedule is wrong/invalid
        throw new \Exception("Weekly schedule is not valid.");
    }

    /**
     * Checks if an email has been set within the email threshold
     *
     * @param AgentConfig $agentConfig
     * @return bool
     */
    private function emailNotSent(AgentConfig $agentConfig)
    {
        $lastEmailTime = $agentConfig->get('failedScreenshotEmail', false);

        if ($lastEmailTime) {
            // Round down the timestamp of the email and the current time to the start of the last hour
            $lastEmailHour = $lastEmailTime - ($lastEmailTime % self::SECONDS_PER_HOUR);
            $currentUnixHour = $this->dateTimeService->getTime() - ($this->dateTimeService->getTime() % self::SECONDS_PER_HOUR);

            $emailNotSentWithinThreshold =
                ($currentUnixHour - $lastEmailHour) >=
                (ScreenshotAlertService::HOURS_TO_SEND_EMAIL * self::SECONDS_PER_HOUR);

            return $emailNotSentWithinThreshold;
        }

        return true;
    }
}
