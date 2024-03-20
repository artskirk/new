<?php

namespace Datto\Util\Email\Generator;

use Datto\AppKernel;
use Datto\Config\ContactInfoRecord;
use Datto\Config\DeviceConfig;
use Datto\Service\Networking\NetworkService;
use Datto\Util\Email\Email;

/**
 * Generates an email to send when an agent or share is added to the device.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class AddAssetEmailGenerator
{
    const SUBJECT_FORMAT = '%s %s has been added to device %s';
    const MESSAGE_FORMAT = 'A new %s, <b>%s</b>, has been added to your device.';

    private NetworkService $networkService;
    private DeviceConfig $deviceConfig;

    public function __construct(
        NetworkService $networkService = null,
        DeviceConfig $deviceConfig = null
    ) {
        $this->networkService = $networkService ?? AppKernel::getBootedInstance()->getContainer()->get(NetworkService::class);
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
    }

    /**
     * Generate the email.
     *
     * @param string $assetType
     * @param string $hostname
     * @return Email
     */
    public function generate($assetType, $hostname): Email
    {
        $cleanShareName = htmlspecialchars($hostname);
        $cleanHostName = htmlspecialchars(trim($this->networkService->getHostname()));

        $contactInfoRecord = new ContactInfoRecord();
        $this->deviceConfig->loadRecord($contactInfoRecord);
        $to = $contactInfoRecord->getEmail();
        $subject = sprintf(
            self::SUBJECT_FORMAT,
            ucfirst($assetType),
            $cleanShareName,
            $cleanHostName
        );
        $message = sprintf(
            self::MESSAGE_FORMAT,
            strtolower($assetType),
            $cleanShareName
        );

        return new Email($to, $subject, $message);
    }
}
