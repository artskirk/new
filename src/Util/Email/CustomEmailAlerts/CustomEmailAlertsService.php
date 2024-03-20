<?php

namespace Datto\Util\Email\CustomEmailAlerts;

use Datto\AppKernel;
use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Config\DeviceConfig;
use Datto\Device\Serial;
use Datto\Feature\FeatureService;
use Datto\Service\Networking\NetworkService;
use Datto\Utility\ByteUnit;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\ZFS\ZfsDatasetService;
use Exception;

/**
 * @author Peter Salu <psalu@datto.com>
 * @author Andrew Cope <acope@datto.com>
 */
class CustomEmailAlertsService
{
    const SCREENSHOT_SECTION = 'screenshots';
    const WEEKLYS_SECTION = 'weeklys';
    const CRITICAL_SECTION = 'critical';
    const WARNING_SECTION = 'missed';
    const LOGS_SECTION = 'logs';
    const GROWTH_SECTION = 'growth';
    const NOTICE_SECTION = 'notice';

    const DEFAULT_SCREENSHOT_SUBJECT = 'Bootable Screenshot for -agenthostname on -devicehostname (-sn) -shot';
    const DEFAULT_WEEKLYS_SUBJECT = 'Weekly Backup Report for -devicehostname';
    const DEFAULT_CRITICAL_SUBJECT = 'CRITICAL ERROR for -agenthostname on -devicehostname (-sn)';
    const DEFAULT_WARNING_SUBJECT = 'Warning for -agenthostname on -devicehostname (-sn)';
    const DEFAULT_LOGS_SUBJECT = 'Logs for -agenthostname on -devicehostname (-sn)';
    const DEFAULT_GROWTH_SUBJECT = 'Growth Report for -agenthostname on -devicehostname (-sn)';
    const DEFAULT_NOTICE_SUBJECT = '';

    const CANNOT_MODIFY_EXCEPTION_MESSAGE = 'Cannot modify this section due to device configuration';

    private CustomEmailAlerts $customEmailAlerts;
    private Serial $deviceSerial;
    private DeviceConfig $deviceConfig;
    private FeatureService $featureService;
    private ZfsDatasetService $zfsDatasetService;
    private DateTimeService $dateTimeService;
    private DateTimeZoneService $dateTimeZoneService;
    private AssetService $assetService;
    private NetworkService $networkService;

    public function __construct(
        CustomEmailAlerts $customEmailAlerts = null,
        Serial $deviceSerial = null,
        DeviceConfig $deviceConfig = null,
        FeatureService $featureService = null,
        ZfsDatasetService $zfsDatasetService = null,
        DateTimeService $dateTimeService = null,
        DateTimeZoneService $dateTimeZoneService = null,
        AssetService $assetService = null,
        NetworkService $networkService = null
    ) {
        $this->customEmailAlerts = $customEmailAlerts ?? new CustomEmailAlerts();
        $this->deviceSerial = $deviceSerial ?? new Serial();
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->featureService = $featureService ?? new FeatureService();
        $this->zfsDatasetService = $zfsDatasetService ?? new ZfsDatasetService();
        $this->dateTimeService = $dateTimeService ?? new DateTimeService();
        $this->dateTimeZoneService = $dateTimeZoneService ?? new DateTimeZoneService();
        $this->assetService = $assetService ?? new AssetService();
        $this->networkService = $networkService ?? AppKernel::getBootedInstance()->getContainer()->get(NetworkService::class);
    }

    /**
     * Get either the customized email subjects on file or the default email subjects
     *
     * @return array the keyed array of email subject sections
     */
    public function getSubjects()
    {
        if ($this->customEmailAlerts->fileExists()) {
            return $this->customEmailAlerts->read();
        } else {
            return $this->buildDefaultSubjects();
        }
    }

    /**
     * Build the default email subject array
     *
     * @return array
     */
    public function buildDefaultSubjects()
    {
        return array(
            self::SCREENSHOT_SECTION => self::DEFAULT_SCREENSHOT_SUBJECT,
            self::WEEKLYS_SECTION => self::DEFAULT_WEEKLYS_SUBJECT,
            self::CRITICAL_SECTION => self::DEFAULT_CRITICAL_SUBJECT,
            self::WARNING_SECTION => self::DEFAULT_WARNING_SUBJECT,
            self::LOGS_SECTION => self::DEFAULT_LOGS_SUBJECT,
            self::GROWTH_SECTION => self::DEFAULT_GROWTH_SUBJECT,
            self::NOTICE_SECTION => self::DEFAULT_NOTICE_SUBJECT
        );
    }

    /**
     * Attempt to modify the email subject corresponding to the section
     *
     * @param string $section
     * @param string $subject
     */
    public function setSubject($section, $subject)
    {
        if (!$section || !$subject) {
            throw new Exception('Fields cannot be blank');
        }

        $applicableEmailSections = $this->getApplicableSectionsForDevice();

        if (!in_array($section, $applicableEmailSections)) {
            throw new Exception(self::CANNOT_MODIFY_EXCEPTION_MESSAGE);
        }

        $emailSubjects = $this->getSubjects();
        $emailSubjects[$section] = $subject;
        $this->customEmailAlerts->write($emailSubjects);
    }

    /**
     * Replaces key values in a subject line for custom email alerts.
     *
     * @param string $section
     * @param string $assetKey
     * @param array $customReplacements ex. ['-projectname' => 'os2']
     * @return string
     */
    public function formatSubject(string $section, $assetKey = null, array $customReplacements = []): string
    {
        $subjects = $this->getSubjects();
        return $this->format($subjects[$section], $assetKey, $customReplacements);
    }

    /**
     * Replaces key values in a string for custom email alerts.
     *
     * @param string $subjectFormat
     * @param string $assetKey
     * @param array $customReplacements
     * @return string
     */
    private function format(string $subjectFormat, $assetKey = null, array $customReplacements = []): string
    {
        $homeDataset = $this->zfsDatasetService->getDataset(ZfsDatasetService::HOMEPOOL_HOME_DATASET);
        $usedBytes = $homeDataset->getAvailableSpace();
        $freeBytes = $homeDataset->getUsedSpace();
        $percentUsed = (int)($usedBytes/$freeBytes);

        $replacements = [
            '-model' => $this->deviceConfig->getDisplayModel(),
            '-sn' => $this->deviceSerial->get(),
            '-freespace' => (int)ByteUnit::BYTE()->toGiB($freeBytes) . 'G',
            '-usedspace' => (int)ByteUnit::BYTE()->toGiB($usedBytes) . 'G',
            '-percentused' => $percentUsed . '%',
            '-capacity' => (int)ByteUnit::BYTE()->toGiB($homeDataset->getQuota()) . 'G',
            '-datetime' => $this->dateTimeService->getDate(
                $this->dateTimeZoneService->universalDateFormat('date-time'),
                $this->dateTimeService->getTime()
            ),
            '-unixtime' => $this->dateTimeService->getTime(),
            '-devicehostname' => $this->networkService->getHostname()
        ];

        if ($assetKey !== null) {
            $asset = $this->assetService->get($assetKey);
            $lastPoint = $asset->getLocal()->getRecoveryPoints()->getLast();
            $lastPointEpoch = $lastPoint ? $lastPoint->getEpoch() : 0;

            // Get all possible value replacements - order does matter, avoid recursion
            $replacements = array_merge(
                $replacements,
                [
                    '-agenthostname' => $asset->getDisplayName(),
                    '-fqdn' => $asset->getPairName(),
                    '-agentIP' => $asset->getPairName(),
                    '-last' => $this->dateTimeService->getDate('d/m/y g:i:sa', $lastPointEpoch),
                ]
            );

            if ($asset->isType(AssetType::AGENT)) {
                /** @var Agent $asset */
                $operatingSystem = $asset->getOperatingSystem();
                $driver = $asset->getDriver();

                $replacements = array_merge(
                    $replacements,
                    [
                        '-os' => $operatingSystem->getName() . ' ' . $operatingSystem->getVersion(),
                        '-ver' => $driver->getDriverVersion(),
                        '-agentused' => $asset->getUsedLocally(),
                        '-agentsnaps' => $asset->getUsedBySnapshots(),
                    ]
                );
            }
        }

        $replacements = array_merge($replacements, $customReplacements);

        // Separate the keys and values
        $replacementKeys = array_keys($replacements);
        $replacementValues = array_values($replacements);

        // Do ONE replacement rather than multiple
        return str_replace($replacementKeys, $replacementValues, $subjectFormat);
    }

    /**
     * Return all applicable custom email alert sections
     *
     * @return array
     */
    private function getApplicableSectionsForDevice(): array
    {
        $isSnapNas = $this->deviceConfig->has(DeviceConfig::KEY_IS_SNAPNAS);
        $canScreenshot = !$this->deviceConfig->isScreenshotDisabled() &&
            $this->featureService->isSupported(FeatureService::FEATURE_VERIFICATIONS);
        $applicableSections = array();

        if (!$isSnapNas) {
            if ($canScreenshot) {
                $applicableSections[] = self::SCREENSHOT_SECTION;
            }
            $applicableSections[] = self::WEEKLYS_SECTION;
            $applicableSections[] = self::LOGS_SECTION;
        } else {
            $applicableSections[] = self::GROWTH_SECTION;
        }
        $applicableSections[] = self::CRITICAL_SECTION;
        $applicableSections[] = self::WARNING_SECTION;

        return $applicableSections;
    }
}
