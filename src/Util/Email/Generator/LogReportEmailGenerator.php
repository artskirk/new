<?php

namespace Datto\Util\Email\Generator;

use Datto\AppKernel;
use Datto\Asset\Asset;
use Datto\Config\DeviceConfig;
use Datto\Device\Serial;
use Datto\Service\Networking\NetworkService;
use Datto\Util\Email\CustomEmailAlerts\CustomEmailAlertsService;
use Datto\Reporting\LogReport;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\Util\Email\Email;

/**
 * This class handles creating message and subject for Log Report emails
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class LogReportEmailGenerator
{
    const FAIL_CODES = [4,7,11,14,17,18,104,450,612,630,631];

    private LogReport $logReport;
    private DateTimeZoneService $dateTimeZoneService;
    private DateTimeService $dateTimeService;
    private DeviceConfig $deviceConfig;
    private CustomEmailAlertsService $customEmailAlertsService;
    private Serial $deviceSerial;
    private NetworkService $networkService;

    public function __construct(
        LogReport $logReport = null,
        DateTimeZoneService $dateTimeZoneService = null,
        DateTimeService $dateTimeService = null,
        DeviceConfig $deviceConfig = null,
        CustomEmailAlertsService $customEmailAlertsService = null,
        Serial $deviceSerial = null,
        NetworkService $networkService = null
    ) {
        $this->logReport = $logReport ?? new LogReport();
        $this->dateTimeZoneService = $dateTimeZoneService ?? new DateTimeZoneService();
        $this->dateTimeService = $dateTimeService ?? new DateTimeService();
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->customEmailAlertsService = $customEmailAlertsService ?? new CustomEmailAlertsService();
        $this->deviceSerial = $deviceSerial ?? new Serial();
        $this->networkService = $networkService ?? AppKernel::getBootedInstance()->getContainer()->get(NetworkService::class);
    }

    /**
     * Generate the email.
     *
     * @param Asset $asset
     * @return Email
     */
    public function generate(Asset $asset): Email
    {
        $to = implode(',', $asset->getEmailAddresses()->getLog());
        $subject = $this->customEmailAlertsService->formatSubject(CustomEmailAlertsService::LOGS_SECTION, $asset->getKeyName());
        $info = [
            'agent' => $asset->getDisplayName(),
            'hostname' => $this->networkService->getHostname(),
            'agentIP' => $asset->getPairName(),
            'errorDate' => $this->dateTimeService->getDate(
                $this->dateTimeZoneService->universalDateFormat('date'),
                $this->dateTimeService->stringToTime('yesterday')
            ),
            'errorLines' => $this->getFormattedLogString($asset->getKeyName()),
            'serial' => $this->deviceSerial->get(),
            'model' => $this->deviceConfig->getDisplayModel(),
            'deviceID' => (int)$this->deviceConfig->get('deviceID'),
            'type' => 'sendLogs'
        ];

        return new Email($to, $subject, $info);
    }

    /**
     * Format log strings for mailing, human-ize times and replace hashes
     *
     * @param string $assetKey
     * @return string
     */
    private function getFormattedLogString(string $assetKey): string
    {
        $formattedLogString = '';
        $logs = $this->logReport->getLastFiveHundredLogMessages($assetKey);
        $dateFormat = $this->dateTimeZoneService->universalDateFormat('date-time');
        foreach ($logs as $logEntry) {
            if ((int)$logEntry['time']) {
                $timestamp = date($dateFormat, (int)$logEntry['time']);
                $line = $timestamp . ' - ' . str_replace('#', '', $logEntry['msg']);
                if (in_array(substr($logEntry['code'], 3), self::FAIL_CODES, true)) {
                    $line = '<b>' . $line . '</b>';
                }
                $formattedLogString .= $line . PHP_EOL;
            }
        }
        return $formattedLogString;
    }
}
