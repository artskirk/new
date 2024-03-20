<?php

namespace Datto\Util\Email\Generator;

use Datto\AppKernel;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Config\DeviceConfig;
use Datto\Device\Serial;
use Datto\Service\Networking\NetworkService;
use Datto\Util\Email\CustomEmailAlerts\CustomEmailAlertsService;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\Util\Email\Email;
use Datto\Log\DeviceLoggerInterface;

/**
 * Generates an email that gets sent when a critical alert is encountered by the device.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class CriticalEmailGenerator
{
    const EMAIL_TYPE_CRITICAL = 'sendCritical';

    private AssetService $assetService;
    private Serial $deviceSerial;
    private DeviceConfig $deviceConfig;
    private DateTimeService $dateTimeService;
    private DateTimeZoneService $dateTimeZoneService;
    private CustomEmailAlertsService $customEmailAlertsService;
    private ?DeviceLoggerInterface $logger;
    private NetworkService $networkService;

    public function __construct(
        AssetService $assetService = null,
        Serial $deviceSerial = null,
        DeviceConfig $deviceConfig = null,
        DateTimeService $dateTimeService = null,
        DateTimeZoneService $dateTimeZoneService = null,
        CustomEmailAlertsService $customEmailAlertsService = null,
        DeviceLoggerInterface $logger = null,
        NetworkService $networkService = null
    ) {
        $this->assetService = $assetService ?? new AssetService();
        $this->deviceSerial = $deviceSerial ?? new Serial();
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->dateTimeService = $dateTimeService ?? new DateTimeService();
        $this->dateTimeZoneService = $dateTimeZoneService ?? new DateTimeZoneService();
        $this->customEmailAlertsService = $customEmailAlertsService ?? new CustomEmailAlertsService();
        $this->logger = $logger;
        $this->networkService = $networkService ?? AppKernel::getBootedInstance()->getContainer()->get(NetworkService::class);
    }

    /**
     * Generate the email.
     *
     * @param string $assetKey
     * @param string $lines
     * @param bool $isScreenshot
     * @return Email
     */
    public function generate($assetKey, $lines, $isScreenshot = false): Email
    {
        $logger = $this->logger ?: LoggerFactory::getAssetLogger($assetKey);
        $asset = $this->assetService->get($assetKey);

        $logger->info('SPM0910 Critical Backup Error Email Requested');

        $serial = strtoupper($this->deviceSerial->get());
        $sirisName = $this->networkService->getHostname();

        $localizedDateFormat = $this->dateTimeZoneService->universalDateFormat('time-day-date');
        $abbreviatedTimeZone = $this->dateTimeZoneService->abbreviateTimeZone($this->dateTimeZoneService->getTimeZone());
        $dateString = $this->dateTimeService->getDate($localizedDateFormat) . ' ' . $abbreviatedTimeZone;

        $displayModel = $this->deviceConfig->getDisplayModel();

        $to = implode(',', $this->getCriticalEmailRecipients($asset, $isScreenshot));

        $subject = $this->customEmailAlertsService->formatSubject(
            CustomEmailAlertsService::CRITICAL_SECTION,
            $assetKey
        );

        $info = array(
            'agent' => $asset->getPairName(),
            'agentIP' => $assetKey,
            'hostname' => $sirisName,
            'errorDate' => $dateString,
            'errorLines' => $lines,
            'serial' => $serial,
            'model' => $displayModel,
            'deviceID' => (int)$this->deviceConfig->get('deviceID'),
            'type' => self::EMAIL_TYPE_CRITICAL
        );

        $logger->info('CEG0001 Critical Notice will be sent', ['recipient' => $to, 'subject' => $subject]);

        return new Email($to, $subject, $info);
    }

    /**
     * @param Asset $asset
     * @param bool $isScreenshot
     * @return array
     */
    private function getCriticalEmailRecipients(Asset $asset, bool $isScreenshot): array
    {
        if ($isScreenshot) {
            $recipients = $asset->getEmailAddresses()->getScreenshotFailed();
        } else {
            $recipients = $asset->getEmailAddresses()->getCritical();
        }

        return $recipients;
    }
}
