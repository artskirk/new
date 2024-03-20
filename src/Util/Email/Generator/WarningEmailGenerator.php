<?php

namespace Datto\Util\Email\Generator;

use Datto\AppKernel;
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
 * Generates an email that gets sent when a warning is encountered by the device.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class WarningEmailGenerator
{
    const EMAIL_TYPE_WARNING = 'sendMissed';

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
     * @param string $message
     * @return Email
     */
    public function generate(string $assetKey, string $message): Email
    {
        $logger = $this->logger ?: LoggerFactory::getAssetLogger($assetKey);
        $asset = $this->assetService->get($assetKey);

        $logger->info('SPM0920 Backup Warning Notice Email Requested');

        $serial = strtoupper($this->deviceSerial->get());
        $sirisName = $this->networkService->getHostname();

        $localizedDateFormat = $this->dateTimeZoneService->universalDateFormat('time-day-date');
        $abbreviatedTimeZone = $this->dateTimeZoneService->abbreviateTimeZone($this->dateTimeZoneService->getTimeZone());
        $dateString = $this->dateTimeService->getDate($localizedDateFormat) . ' ' . $abbreviatedTimeZone;

        $to = implode(',', $asset->getEmailAddresses()->getWarning());

        $subject = $this->customEmailAlertsService->formatSubject(CustomEmailAlertsService::WARNING_SECTION, $assetKey);

        $info = array(
            'agent' => $asset->getPairName(),
            'hostname' => $sirisName,
            'agentIP' => $assetKey,
            'errorDate' => $dateString,
            'errorLines' => $message,
            'serial' => $serial,
            'model' => $this->deviceConfig->getDisplayModel(),
            'deviceID' => (int)$this->deviceConfig->get('deviceID'),
            'type' => self::EMAIL_TYPE_WARNING
        );

        $logger->info('SPM0921 Backup Warning Notice will be sent', ['recipient' => $to, 'subject' => $subject]);

        return new Email($to, $subject, $info);
    }
}
