<?php

namespace Datto\Util\Email\Generator;

use Datto\AppKernel;
use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Service\Networking\NetworkService;
use Datto\Util\DateTimeZoneService;
use Datto\Util\Email\Email;
use Datto\Util\Email\CustomEmailAlerts\CustomEmailAlertsService;
use Datto\Log\DeviceLoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Generates an email to be sent when a screenshot verification is finished.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class ScreenshotEmailGenerator
{
    const EMAIL_TYPE_SCREENSHOT = 'screenshot';
    const EMAIL_TYPE_SCREENSHOT_SKIPPED = 'screenshotSkipped';
    const RESULT_SUCCESS = 'success';
    const RESULT_FAILURE = 'fail';
    const RESULT_SKIPPED = 'skipped';

    private AssetService $assetService;
    private DateTimeService $dateTimeService;
    private DateTimeZoneService $dateTimeZoneService;
    private Filesystem $filesystem;
    private NetworkService $networkService;
    private DeviceConfig $deviceConfig;
    private CustomEmailAlertsService $customEmailAlertsService;
    private DeviceLoggerInterface $logger;
    private TranslatorInterface $translator;

    public function __construct(
        TranslatorInterface $translator,
        AssetService $assetService = null,
        DateTimeService $dateTimeService = null,
        DateTimeZoneService $dateTimeZoneService = null,
        Filesystem $filesystem = null,
        NetworkService $networkService = null,
        DeviceConfig $deviceConfig = null,
        CustomEmailAlertsService $customEmailAlertsService = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->translator = $translator;
        $this->assetService = $assetService ?? new AssetService();
        $this->dateTimeService = $dateTimeService ?? new DateTimeService();
        $this->dateTimeZoneService = $dateTimeZoneService ?? new DateTimeZoneService();
        $this->filesystem = $filesystem ?? new Filesystem(new ProcessFactory());
        $this->networkService = $networkService ?? AppKernel::getBootedInstance()->getContainer()->get(NetworkService::class);
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->customEmailAlertsService = $customEmailAlertsService ?? new CustomEmailAlertsService();
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
    }

    /**
     * Generate the email.
     *
     * @param string $assetKey
     * @param int $snapshot
     * @param bool $success Overall screenshot success
     * @param bool $scriptSuccess Custom script verification success
     * @param bool $osUpdatePending
     * @param bool $isTestEmail
     * @param bool $skippedScreenshot
     * @return Email
     */
    public function generate(
        $assetKey,
        $snapshot,
        $success,
        $scriptSuccess,
        $osUpdatePending,
        $isTestEmail = false,
        bool $skippedScreenshot = false
    ): Email {
        $this->logger->setAssetContext($assetKey);
        $asset = $this->assetService->get($assetKey);

        $this->logger->info('SPM0900 VM Screenshot Notice Email Requested');

        if ($isTestEmail) {
            $fileLocation = ScreenshotFileRepository::SAMPLE_SCREENSHOT_IMAGE_PATH;
        } else {
            $fileLocation = ScreenshotFileRepository::getScreenshotImagePath($assetKey, $snapshot);
        }

        $uploadedScreenshotName = md5($this->dateTimeService->getMicroTime()) .
            ScreenshotFileRepository::SCREENSHOT_EXTENSION;
        $files[$uploadedScreenshotName] = $this->filesystem->fileGetContents($fileLocation);
        $this->logger->info('SPM0898 Uploading screenshot to webserver', ['path' => $fileLocation]);

        $localizedDateFormat = $this->dateTimeZoneService->universalDateFormat('time-day-date');
        $abbreviatedTimeZone = $this->dateTimeZoneService->abbreviateTimeZone($this->dateTimeZoneService->getTimeZone());
        $dateString = $this->dateTimeService->getDate($localizedDateFormat, $snapshot) . ' ' . $abbreviatedTimeZone;

        $subject = $this->customEmailAlertsService->formatSubject(
            CustomEmailAlertsService::SCREENSHOT_SECTION,
            $assetKey,
            ['-shot' => $success ? 'SUCCEEDED' : 'FAILED']
        );

        $info = array(
            'agent' => $asset->getPairName(),
            'hostname' => $this->networkService->getHostname(),
            'snapDate' => $dateString,
            'screenshotName' => $uploadedScreenshotName,
            'deviceID' => (int)$this->deviceConfig->get('deviceID'),
            'type' => $skippedScreenshot ? static::EMAIL_TYPE_SCREENSHOT_SKIPPED : static::EMAIL_TYPE_SCREENSHOT
        );
        $meta = array(
            'deviceID' => (int)$this->deviceConfig->get('deviceID'),
            'hostname' => $assetKey,
            'snapshotID' => $snapshot,
            'result' => $success ? static::RESULT_SUCCESS : static::RESULT_FAILURE
        );

        if (!$success) {
            $failTextFile = ScreenshotFileRepository::getScreenshotErrorTextPath($assetKey, $snapshot);
            $info['failText'] = $this->filesystem->fileGetContents($failTextFile);

            if ($osUpdatePending) {
                $info['failText'] = $info['failText'] . "\n" .
                    $this->translator->trans('assets.recoverypoints.verification.dropdown.screenshot.osUpdatePending');
            }
        }

        if (!$scriptSuccess) {
            $info['failText'] = $info['failText'] . "\nOne or more verification scripts for this agent have failed. "
                . 'Please see device for details.';
        }

        $emailSettings = $asset->getEmailAddresses();
        $recipients = $success ? $emailSettings->getScreenshotSuccess() : $emailSettings->getScreenshotFailed();

        $to = implode(',', $recipients);

        $this->logger->info('SPM0901 VM Screenshot email will be sent', ['success' => $success, 'snapshot' => $snapshot, 'mailTo' => $to, 'subject' => $subject]);

        return new Email($to, $subject, $info, $files, $meta);
    }
}
