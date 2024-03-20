<?php

namespace Datto\Util\Email\Generator;

use Datto\AppKernel;
use Datto\Config\ContactInfoRecord;
use Datto\Config\DeviceConfig;
use Datto\Device\Serial;
use Datto\Service\Networking\NetworkService;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\Util\Email\Email;

/**
 * Generates an email to alert a partner that their device has rebooted.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class RebootEmailGenerator
{
    const SUBJECT_FORMAT = 'Datto ALERT SN: %s Host: %s Type: Successful Reboot.';
    const MESSAGE_FORMAT = 'The scheduled reboot of the following device was successful. SN: %s Host: %s. The device was up at around %s.';

    private Serial $deviceSerial;
    private DeviceConfig $deviceConfig;
    private DateTimeService $dateTimeService;
    private DateTimeZoneService $dateTimeZoneService;
    private NetworkService $networkService;

    public function __construct(
        Serial $deviceSerial = null,
        DeviceConfig $deviceConfig = null,
        DateTimeService $dateTimeService = null,
        DateTimeZoneService $dateTimeZoneService = null,
        NetworkService $networkService = null
    ) {
        $this->deviceSerial = $deviceSerial ?? new Serial();
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->dateTimeService = $dateTimeService ?? new DateTimeService();
        $this->dateTimeZoneService = $dateTimeZoneService ?? new DateTimeZoneService();
        $this->networkService = $networkService ?? AppKernel::getBootedInstance()->getContainer()->get(NetworkService::class);
    }

    /**
     * Generate the email.
     *
     * @return Email
     */
    public function generate(): Email
    {
        $contactInfoRecord = new ContactInfoRecord();
        $this->deviceConfig->loadRecord($contactInfoRecord);
        $to = $contactInfoRecord->getEmail();

        $serialNumber = $this->deviceSerial->get();
        $hostname = $this->networkService->getHostname();

        $localizedDateFormat = $this->dateTimeZoneService->universalDateFormat('time-day-date');
        $currentTime = $this->dateTimeService->getDate($localizedDateFormat);

        $subject = sprintf(
            self::SUBJECT_FORMAT,
            $serialNumber,
            $hostname
        );

        $message = sprintf(
            self::MESSAGE_FORMAT,
            $serialNumber,
            $hostname,
            $currentTime
        );

        return new Email($to, $subject, $message);
    }
}
