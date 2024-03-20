<?php

namespace Datto\Util\Email\Generator;

use Datto\Alert\AlertManager;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;
use Datto\Config\DeviceConfig;
use Datto\Device\Serial;
use Datto\Resource\DateTimeService;
use Datto\Service\Networking\NetworkService;
use Datto\Util\Email\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Generates an email that summarizes all alerts seen for all agents. Part of advanced alerting.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class AlertSummaryReportGenerator
{
    const EMAIL_TYPE_ALERT_SUMMARY = 'sendAlertSummary';

    private DeviceConfig $deviceConfig;
    private Serial $deviceSerial;
    private DateTimeService $dateTimeService;
    private TranslatorInterface $translator;
    private Environment $twig;
    private AlertManager $alertManager;
    private NetworkService $networkService;

    public function __construct(
        DeviceConfig $deviceConfig,
        DateTimeService $dateTimeService,
        TranslatorInterface $translator,
        Environment $twig,
        AlertManager $alertManager,
        NetworkService $networkService,
        Serial $deviceSerial = null
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->dateTimeService = $dateTimeService;
        $this->translator = $translator;
        $this->twig = $twig;
        $this->alertManager = $alertManager;
        $this->networkService = $networkService;
        $this->deviceSerial = $deviceSerial ?? new Serial();
    }

    /**
     * Generate the email.
     *
     * @param array $assets
     * @return Email
     */
    public function generate(array $assets): Email
    {
        $to = implode(',', $this->getEmailAddresses($assets));
        $subject = $this->getSubject();
        $message = $this->getMessage($assets);

        return new Email($to, $subject, $message);
    }

    /**
     * Get the list of email addresses to send this report to.
     * The list currently consists of the combined list of critical email
     * addresses for all assets.
     *
     * @param Asset[] $assets Array of all assets
     * @return string[] List of email addresses to send the report to
     */
    public function getEmailAddresses(array $assets): array
    {
        $emails = [];

        foreach ($assets as $asset) {
            $criticalEmails = $asset->getEmailAddresses()->getCritical();
            $emails = array_merge($emails, $criticalEmails);
        }

        return array_unique($emails);
    }

    /**
     * @param Asset[] $assets Array of all assets
     * @return array Array of message data or empty array if nothing to report.
     */
    private function getMessage(array $assets): array
    {
        $twigAssetList = [];

        foreach ($assets as $asset) {
            $assetKeyName = $asset->getKeyName();
            $alerts = $this->alertManager->getAllAlerts($assetKeyName);

            $twigAssetList[] = [
                'name' => $asset->getDisplayName(),
                'isShare' => $asset->isType(AssetType::SHARE),
                'isSuppressed' => $this->alertManager->isAssetSuppressed($assetKeyName),
                'alerts' => $alerts
            ];
        }

        $data = $this->twig->render('Report/AlertSummary/index.html.twig', ['assets' => $twigAssetList]);
        $data = preg_replace('/^\s+/m', '', $data);  // compact the message

        $info = array(
            'hostname' => $this->networkService->getHostname(),
            'errorDate' => $this->dateTimeService->format('g:ia l n/j/Y'),
            'serial' => $this->deviceSerial->get(),
            'model' => $this->deviceConfig->getDisplayModel(),
            'deviceID' => (int)$this->deviceConfig->getRaw('deviceID'),
            'messageBody' => $data,
            'type' => self::EMAIL_TYPE_ALERT_SUMMARY
        );

        return $info;
    }

    /**
     * Generate Alert Summary report email subject string.
     *
     * @return string
     */
    private function getSubject(): string
    {
        $hostname = $this->networkService->getHostname();
        $serial = $this->deviceSerial->get();
        $parameters = ['%hostname%' => $hostname, '%serial%' => $serial];

        return $this->translator->trans('report.alert.summary.subject', $parameters);
    }
}
