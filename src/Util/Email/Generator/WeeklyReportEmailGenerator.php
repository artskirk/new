<?php

namespace Datto\Util\Email\Generator;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Datto\Asset\Agent\Agent;
use Datto\Config\DeviceConfig;
use Datto\Device\Serial;
use Datto\Service\Networking\NetworkService;
use Datto\Util\Email\CustomEmailAlerts\CustomEmailAlertsService;
use Datto\Feature\FeatureService;
use Datto\Reporting\Screenshots;
use Datto\Reporting\Snapshots;
use Datto\Util\DateTimeZoneService;
use Datto\Util\Email\Email;
use Datto\Common\Utility\Filesystem;
use Datto\ZFS\ZfsService;
use Exception;

/**
 * This class handles creating message and subject for weekly report emails
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class WeeklyReportEmailGenerator
{
    private Filesystem $filesystem;
    private DateTimeZoneService $dateTimeZoneService;
    private DeviceConfig $deviceConfig;
    private CustomEmailAlertsService $customEmailAlertsService;
    private Screenshots $screenshots;
    private Snapshots $snapshots;
    private Serial $deviceSerial;
    private FeatureService $featureService;
    private ZfsService $zfsService;
    private NetworkService $networkService;

    public function __construct(
        Filesystem $filesystem,
        DateTimeZoneService $dateTimeZoneService,
        DeviceConfig $deviceConfig,
        CustomEmailAlertsService $customEmailAlertsService,
        Screenshots $screenshots,
        Snapshots $snapshots,
        Serial $deviceSerial,
        FeatureService $featureService,
        ZfsService $zfsService,
        NetworkService $networkService
    ) {
        $this->filesystem = $filesystem;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->deviceConfig = $deviceConfig;
        $this->customEmailAlertsService = $customEmailAlertsService;
        $this->screenshots = $screenshots;
        $this->snapshots = $snapshots;
        $this->deviceSerial = $deviceSerial;
        $this->featureService = $featureService;
        $this->zfsService = $zfsService;
        $this->networkService = $networkService;
    }

    /**
     * Generate the email.
     *
     * @param Agent $agent
     * @param DateTimeInterface|null $rangeStart
     * @param DateTimeInterface|null $rangeEnd
     * @return Email
     */
    public function generate(
        Agent $agent,
        DateTimeInterface $rangeStart = null,
        DateTimeInterface $rangeEnd = null
    ): Email {
        $timezone = new DateTimeZone($this->dateTimeZoneService->getTimeZone());
        $rangeStart = $rangeStart ?: new DateTimeImmutable('7 days ago Midnight', $timezone);
        $rangeEnd = $rangeEnd ?: new DateTimeImmutable('yesterday 11:59:59PM', $timezone);

        if ($rangeStart > $rangeEnd) {
            $message = sprintf(
                'Start of range (%s) is greater than end of range (%s)',
                $rangeStart->format(DATE_RFC850),
                $rangeEnd->format(DATE_RFC850)
            );
            throw new Exception($message);
        }

        $recipients = $agent->getEmailAddresses()->getWeekly();
        $to = implode(',', $recipients);

        $section = CustomEmailAlertsService::WEEKLYS_SECTION;
        $subject = $this->customEmailAlertsService->formatSubject($section, $agent->getKeyName());

        $info = [
            'agent' => $agent->getDisplayName(),
            'agentIP' => $agent->getPairName(),
            'hostname' => $this->networkService->getHostName(),
            'serial' => $this->deviceSerial->get(),
            'extra' => $this->getExtraData($agent, $rangeStart, $rangeEnd), //contains all the screenshot/snapshot data
            'model' => $this->deviceConfig->getDisplayModel(),
            'deviceID' => (int)$this->deviceConfig->get('deviceID'),
            'type' => 'sendWeeklyReport'
        ];
        $meta = [
            'hostname' => $agent->getKeyName()
        ];

        return new Email($to, $subject, $info, null, $meta);
    }

    /**
     * Creates 'extra' array, which is used to populate an email template on device-web
     * @param Agent $agent
     * @param DateTimeInterface $rangeStart
     * @param DateTimeInterface $rangeEnd
     * @return array
     */
    private function getExtraData(Agent $agent, DateTimeInterface $rangeStart, DateTimeInterface $rangeEnd): array
    {
        $usageData = $this->getUsageData($agent);
        $snapshotArray = $this->getSnapshotArray($agent, $rangeStart, $rangeEnd);
        $screenshotArray = $this->getScreenshotArray($agent, $rangeStart, $rangeEnd);
        $offsiteData = $this->getOffsiteData($agent);

        $extra = array_merge($usageData, $snapshotArray);
        $extra = array_merge($extra, $screenshotArray);
        $extra = array_merge($extra, $offsiteData);
        return $extra;
    }

    /**
     * Collect usage data fields
     * @param Agent $agent
     * @return array
     */
    private function getUsageData(Agent $agent): array
    {
        // array_shift is needed here because of the double array used below when the value is set.
        // Not changing this so we can parse agents that already have this file created with the double array.
        $usageArray = $this->getLastWeeksUsage($agent);
        $lastWeek = array_shift($usageArray);

        $localUsed = $agent->getUsedLocally();
        $totalSnapshots = count($this->zfsService->getSnapshots($agent->getDataset()->getZfsPath()));
        $this->setLastWeeksUsage($agent, [['space' => "$localUsed GB", 'points' => $totalSnapshots]]);

        return [
            'newSpace' => "$localUsed GB",
            'newPoints' => $totalSnapshots, // Index is misleading. This should be total snapshot count on the device, not new this week
            'oldSpace' => $lastWeek['space'] ?? 'N/A',
            'oldPoints' => $lastWeek['points'] ?? 'N/A'
        ];
    }

    /**
     * Load and return serialized array of data usage for the previous week
     * @param Agent $agent
     * @return array
     */
    private function getLastWeeksUsage(Agent $agent): array
    {
        $serializedUsageFile = Agent::KEYBASE . $agent->getKeyName() . '.storageUsage';
        return $this->filesystem->exists($serializedUsageFile) ?
            unserialize(trim($this->filesystem->fileGetContents($serializedUsageFile)), ['allowed_classes' => false]) :
            [];
    }

    /**
     * Save serialized array of data usage for this week and last week
     * @param Agent $agent
     * @param array $usageArray
     */
    private function setLastWeeksUsage(Agent $agent, array $usageArray)
    {
        $serializedUsageFile = Agent::KEYBASE . $agent->getKeyName() . '.storageUsage';
        $this->filesystem->filePutContents($serializedUsageFile, serialize($usageArray));
    }

    /**
     * Get and format last offsited snapshot
     * @param Agent $agent
     * @return array
     */
    private function getOffsiteData(Agent $agent): array
    {
        if ($agent->getOffsite()->getRecoveryPoints()->getLast() !== null) {
            $lastDate = date(
                $this->dateTimeZoneService->universalDateFormat('date-time'),
                $agent->getOffsite()->getRecoveryPoints()->getLast()->getEpoch()
            );
        } else {
            $lastDate = 'none';
        }
        return [
            'latestOffsite' => $lastDate
        ];
    }

    /**
     * Collect data related to screenshots for email generation
     * @param Agent $agent
     * @param DateTimeInterface $rangeStart
     * @param DateTimeInterface $rangeEnd
     * @return array
     */
    private function getScreenshotArray(
        Agent $agent,
        DateTimeInterface $rangeStart,
        DateTimeInterface $rangeEnd
    ): array {
        $lastSuccessfulEpoch = 0;
        $successfulScreenshots = 0;
        $screenShotsTaken = 0;

        foreach ($this->screenshots->getLogs($agent->getKeyName()) as $screenshot) {
            if ($screenshot['start_time'] < $rangeStart->getTimestamp() ||
                $screenshot['start_time'] > $rangeEnd->getTimestamp()
            ) {
                continue;
            }

            $screenShotsTaken++;
            if ($screenshot['result'] === 'success') {
                $successfulScreenshots++;
                if ($screenshot['start_time'] > $lastSuccessfulEpoch) {
                    $lastSuccessfulEpoch = $screenshot['start_time'];
                }
            }
        }

        $screenshotStatus = '';
        if ($screenShotsTaken > 0) {
            $screenshotStatus = $lastSuccessfulEpoch ? 'booted successfully' : 'failed to boot';
        }

        return [
            'screenshotsDate' => $lastSuccessfulEpoch ? date(
                $this->dateTimeZoneService->universalDateFormat('time-date-short'),
                $lastSuccessfulEpoch
            ) : 'None',
            'screenshotsTaken' => $successfulScreenshots, // Index is misleading. device-webmailer implies any taken screenshot as success
            'screenshotName' => '', //TODO:: this needs to be sorted out, currently no system in place to track previously uploaded screenshots
            'screenshotStatus' => $screenshotStatus,
            'hideScreenshots' => !$this->featureService->isSupported(FeatureService::FEATURE_VERIFICATIONS)
        ];
    }

    /**
     * Collect data related to scheduled/taken snapshots
     * @param Agent $agent
     * @param DateTimeInterface $rangeStart
     * @param DateTimeInterface $rangeEnd
     * @return array
     */
    private function getSnapshotArray(
        Agent $agent,
        DateTimeInterface $rangeStart,
        DateTimeInterface $rangeEnd
    ): array {
        $successfulSnapshots = 0;
        $scheduledSnapshots = 0;

        $loggedSnapshots = $this->snapshots->getLogs($agent->getKeyName());
        foreach ($loggedSnapshots as $loggedSnapshot) {
            if ($loggedSnapshot['start_time'] < $rangeStart->getTimestamp() ||
                $loggedSnapshot['start_time'] > $rangeEnd->getTimestamp()
            ) {
                continue;
            }

            if ($loggedSnapshot['success']) {
                $successfulSnapshots++;
            }
            if ($loggedSnapshot['type'] === 'scheduled') {
                $scheduledSnapshots++;
            }
        }

        return [
            'completedSnaps' => $successfulSnapshots,
            'scheduledSnaps' => $scheduledSnapshots
        ];
    }
}
