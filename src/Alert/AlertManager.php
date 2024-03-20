<?php

namespace Datto\Alert;

use Datto\Asset\AssetRemovalService;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\Serializer\LegacyAlertSerializer;
use Datto\Asset\Serializer\LegacyLastErrorSerializer;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentStateFactory;
use Datto\Config\DeviceConfig;
use Datto\Config\ServerNameConfig;
use Datto\Curl\CurlHelper;
use Datto\Log\Formatter\AbstractFormatter;
use Datto\Resource\DateTimeService;
use Datto\Util\Email\EmailService;
use Datto\Util\Email\Generator\CriticalEmailGenerator;
use Datto\Util\Email\Generator\WarningEmailGenerator;
use Exception;
use Datto\Reporting\Snapshots;
use Datto\Reporting\Screenshots;
use Datto\Billing\Service as BillingService;

/**
 * Processes email alerts for assets.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class AlertManager
{
    /**
     * How many lines of the log file to return to the UI when an error occurs
     */
    const LOG_LINES = 15;

    const ALERT_SUPPRESSION_SECONDS = 3600; // 1 hour in seconds

    const USERNAME_NOT_SPECIFIED = '(not specified)';

    const ASSET_KEY_ALERT_SUPPRESSION = 'emailSupression';
    const DEVICE_KEY_USE_ADVANCED_ALERTING = 'useAdvancedAlerting';
    const DEVICE_KEY_AGENT_ALERT_STATUS = 'agentAlertStatus';

    const ALERT_SUPPRESSION_TYPE_WARNING = 'missed';
    const ALERT_SUPPRESSION_TYPE_CRITICAL = 'critical';
    const ALERT_SUPPRESSION_TYPE_AUDIT = 'audit';

    const VERIFICATION_PREFIXES = ['SCR', 'SCN', 'VER'];
    const BACKUP_PREFIXES = ['BAK', 'BKP', 'SNP'];

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var AgentStateFactory */
    private $agentStateFactory;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var BillingService */
    private $billingService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var LegacyLastErrorSerializer */
    private $lastErrorSerializer;

    /** @var LegacyAlertSerializer */
    private $alertSerializer;

    /** @var CurlHelper */
    private $curlHelper;

    /** @var Screenshots */
    private $screenshots;

    /** @var Snapshots */
    private $snapshots;

    /** @var EmailService */
    private $emailService;

    /** @var CriticalEmailGenerator */
    private $criticalEmailGenerator;

    /** @var WarningEmailGenerator */
    private $warningEmailGenerator;

    private ServerNameConfig $serverNameConfig;

    public function __construct(
        AgentConfigFactory $agentConfigFactory = null,
        AgentStateFactory $agentStateFactory = null,
        DeviceConfig $deviceConfig = null,
        BillingService $billingService = null,
        DateTimeService $dateTimeService = null,
        LegacyLastErrorSerializer $lastErrorSerializer = null,
        LegacyAlertSerializer $alertSerializer = null,
        CurlHelper $curlHelper = null,
        Screenshots $screenshots = null,
        Snapshots $snapshots = null,
        EmailService $emailService = null,
        CriticalEmailGenerator $criticalEmailGenerator = null,
        WarningEmailGenerator $warningEmailGenerator = null,
        ServerNameConfig $serverNameConfig = null
    ) {
        $this->agentConfigFactory = $agentConfigFactory ?: new AgentConfigFactory();
        $this->agentStateFactory = $agentStateFactory ?: new AgentStateFactory();
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->billingService = $billingService ?: new BillingService();
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
        $this->lastErrorSerializer = $lastErrorSerializer ?: new LegacyLastErrorSerializer();
        $this->alertSerializer = $alertSerializer ?: new LegacyAlertSerializer();
        $this->curlHelper = $curlHelper ?: new CurlHelper();
        $this->screenshots = $screenshots ?: new Screenshots();
        $this->snapshots = $snapshots ?: new Snapshots();
        $this->emailService = $emailService ?: new EmailService();
        $this->criticalEmailGenerator = $criticalEmailGenerator ?: new CriticalEmailGenerator();
        $this->warningEmailGenerator = $warningEmailGenerator ?: new WarningEmailGenerator();
        $this->serverNameConfig = $serverNameConfig ?? new ServerNameConfig();
    }

    /**
     * Turns alert emails off for this asset.
     *
     * @param string $assetKey
     */
    public function enableAssetSuppression(string $assetKey): void
    {
        if (!$this->isAdvancedAlertingEnabled()) {
            throw new Exception('ALT0001 Advanced alerting must be enabled to suppress asset.');
        }

        $status = $this->getSuppressedAssets();
        if (!in_array($assetKey, $status, true)) {
            $status[] = $assetKey;
        }

        $this->setSuppressedAssets($status);
    }

    /**
     * Turns alert emails on for this asset.
     *
     * @param string $assetKey
     */
    public function disableAssetSuppression(string $assetKey): void
    {
        if (!$this->isAdvancedAlertingEnabled()) {
            throw new Exception('ALT0002 Advanced alerting must be enabled to enable asset.');
        }

        $suppressedAssetKeys = $this->getSuppressedAssets();
        $newSuppressions = [];
        foreach ($suppressedAssetKeys as $suppressedAssetKey) {
            if ($suppressedAssetKey !== $assetKey) {
                $newSuppressions[] = $suppressedAssetKey;
            }
        }

        $this->setSuppressedAssets($newSuppressions);
        $this->clearAllAlerts($assetKey);
    }

    /**
     * Returns true if the asset is suppressed by advanced alerting.
     *
     * @param string $assetKey
     * @return bool
     */
    public function isAssetSuppressed(string $assetKey): bool
    {
        if (!$this->isAdvancedAlertingEnabled()) {
            return false;
        }

        $suppressedAssetKeys = $this->getSuppressedAssets();
        return in_array($assetKey, $suppressedAssetKeys, true);
    }

    /**
     * Sets an alert and sends out appropriate alert reactions.
     *
     * @param string $logKey Can be an assetKey, or 'device-general'
     * @param string $code Single Internal Message Code (AlertCodes)
     * @param string $message
     * @param string|null $user Username to be associated with the log entry
     * @param array|null Context provided with the alert
     */
    public function processAlert(
        string $logKey,
        string $code,
        string $message,
        $user = self::USERNAME_NOT_SPECIFIED,
        $context = null
    ): void {
        $now = $this->dateTimeService->getTime();
        $useAdvancedAlerting = $this->isAdvancedAlertingEnabled();
        $suppressionType = $this->getAlertSuppressionTypeFromCode($code);

        $isAlertSuppressionActive = $this->isAlertSuppressionActive($logKey, $suppressionType);
        $sendMessage = false;
        if ($this->hasAlert($logKey, $code)) {
            // Alert has been seen before.  Check to see if it times out...
            if (!$useAdvancedAlerting &&
                $suppressionType !== null &&
                $isAlertSuppressionActive === false
            ) {
                $sendMessage = true;
                $suppressionConfig = $this->getAlertSuppression($logKey);
                $suppressionConfig[$suppressionType] = $now + self::ALERT_SUPPRESSION_SECONDS;
                $this->setAlertSuppression($logKey, $suppressionConfig);
            }

            $this->incrementAlert($logKey, $code, $now);
        } else {
            $sendMessage = true;
            $alert = new Alert($code, $message, $now, $now, 1, $user);
            $this->addAlert($logKey, $alert);

            if ($suppressionType === null || $isAlertSuppressionActive === true) {
                $sendMessage = false;
            } elseif (!$useAdvancedAlerting) {
                $suppressionConfig = $this->getAlertSuppression($logKey);
                $suppressionConfig[$suppressionType] = $now + self::ALERT_SUPPRESSION_SECONDS;
                $this->setAlertSuppression($logKey, $suppressionConfig);
            }
        }

        if (in_array($code, AlertCodes::PREVENT_24_HOUR_BACKUP_CHECK_CODES)) {
            $agentState = $this->agentStateFactory->create($logKey);
            $agentState->set('backupError', $this->dateTimeService->getTime());
        }

        // we don't want to send asset specific emails for generic device log messages
        $isDeviceLog = $logKey === AbstractFormatter::DEVICE_KEY;
        if ($sendMessage === true && !$isDeviceLog && $this->billingService->isOutOfService() === false) {
            $this->sendAlertMessage($logKey, $code, $message, $context);
        }

        if (AlertCodes::checkError($code) || AlertCodes::checkUrgentWarning($code)) {
            $this->createErrorReport($logKey, $code, $message, $context);
        }

        if (AlertCodes::checkSpecial($code)) {
            $this->handleSpecial($logKey, $code, $message, $now);
        }

        if (AlertCodes::checkAudit($code) && $sendMessage) {
            $this->handleAudit($logKey, $code, $message, $now);
        }
    }

    /**
     * Checks to see if the new alerting is being used
     *
     * @return bool Success or fail
     */
    public function isAdvancedAlertingEnabled(): bool
    {
        return $this->deviceConfig->has(self::DEVICE_KEY_USE_ADVANCED_ALERTING);
    }

    /**
     * Gets all alerts active for an asset.
     *
     * @param string $assetKey
     * @return Alert[]
     */
    public function getAllAlerts(string $assetKey): array
    {
        $config = $this->agentConfigFactory->create($assetKey);

        return $this->alertSerializer->unserialize($config->get('alertConfig'));
    }

    /**
     * Returns true if this asset currently has this alert active.
     *
     * @param string $assetKey
     * @param string $code Single Internal Message Code (AlertCodes)
     * @return bool
     */
    public function hasAlert(string $assetKey, string $code): bool
    {
        $alerts = $this->getAllAlerts($assetKey);

        foreach ($alerts as $alert) {
            if ($alert->getCode() === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if the asset has any alerts active.
     *
     * @param string $assetKey
     * @return bool
     */
    public function hasAnyAlerts(string $assetKey): bool
    {
        $alerts = $this->getAllAlerts($assetKey);

        return !empty($alerts);
    }

    /**
     * Returns true if the asset has some alerts active and at least one of them is Error-level
     *
     * @param string $assetKey
     * @return bool
     */
    public function hasErrors(string $assetKey): bool
    {
        $alerts = $this->getAllAlerts($assetKey);

        foreach ($alerts as $alert) {
            if (AlertCodes::checkError($alert->getCode())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Increments the numberSeen field and sets lastSeen on an active alert for an asset.
     *
     * @param string $assetKey
     * @param string $code Single Internal Message Code (AlertCodes)
     * @param int $lastSeen
     */
    public function incrementAlert(string $assetKey, string $code, int $lastSeen): void
    {
        $alerts = $this->getAllAlerts($assetKey);

        foreach ($alerts as $alert) {
            if ($alert->getCode() === $code) {
                $alert->setNumberSeen($alert->getNumberSeen() + 1);
                $alert->setLastSeen($lastSeen);
                break;
            }
        }

        $this->setAlerts($assetKey, $alerts);
    }

    /**
     * Removes the active alert from an agent.
     *
     * @param string $assetKey
     * @param string $code
     */
    public function clearAlert(string $assetKey, string $code): void
    {
        $this->clearAlerts($assetKey, [$code]);
    }

    /**
     * Removes multiple active alerts from the agent.
     *
     * @param string $assetKey
     * @param array $codes
     */
    public function clearAlerts(string $assetKey, array $codes): void
    {
        $alerts = $this->getAllAlerts($assetKey);

        $newAlerts = [];
        foreach ($alerts as $alert) {
            if (in_array($alert->getCode(), $codes, true)) {
                // If it's an error, we need to also remove it from .lastError
                if (AlertCodes::checkError($alert->getCode()) || AlertCodes::checkUrgentWarning($alert->getCode())) {
                    $this->clearError($assetKey, $alert->getCode());
                }
            } else {
                $newAlerts[] = $alert;
            }
        }

        $this->setAlerts($assetKey, $newAlerts);
    }

    /**
     * Removes all active alerts for the asset.
     *
     * @param string $assetKey
     */
    public function clearAllAlerts(string $assetKey): void
    {
        $agentConfig = $this->agentConfigFactory->create($assetKey);

        $this->setAlerts($assetKey, []);
        $agentConfig->clear('lastError');
    }

    /**
     * Private function which sets the suppression status.
     *
     * @param array $assetKeys
     */
    private function setSuppressedAssets(array $assetKeys): void
    {
        if (empty($assetKeys)) {
            $this->deviceConfig->clear(self::DEVICE_KEY_AGENT_ALERT_STATUS);
        } else {
            sort($assetKeys);
            $this->deviceConfig->set(self::DEVICE_KEY_AGENT_ALERT_STATUS, json_encode($assetKeys));
        }
    }

    /**
     * Returns a list of all suppressed assets.
     *
     * @return array
     */
    private function getSuppressedAssets(): array
    {
        if ($this->deviceConfig->has(self::DEVICE_KEY_AGENT_ALERT_STATUS)) {
            return json_decode($this->deviceConfig->get(self::DEVICE_KEY_AGENT_ALERT_STATUS), true);
        }

        return [];
    }

    /**
     * Matches an alert code with its corresponding suppression type in the .emailSupression key.
     * @param string $code
     * @return null|string
     */
    private function getAlertSuppressionTypeFromCode(string $code)
    {
        $type = null;

        if (AlertCodes::checkWarning($code) || AlertCodes::checkUrgentWarning($code)) {
            $type = self::ALERT_SUPPRESSION_TYPE_WARNING;
        }
        if (AlertCodes::checkCritical($code)) {
            $type = self::ALERT_SUPPRESSION_TYPE_CRITICAL;
        }
        if (AlertCodes::checkAudit($code)) {
            $type = self::ALERT_SUPPRESSION_TYPE_AUDIT;
        }

        return $type;
    }

    /**
     * Sends a Curl request out to the audit script on the webserver
     *
     * @param string $assetKey
     * @param string $code
     * @param string $message
     * @param int $time
     */
    private function handleAudit(string $assetKey, string $code, string $message, int $time): void
    {
        $endpoint = 'https://' . $this->serverNameConfig->getServer(ServerNameConfig::DEVICE_DATTOBACKUP_COM) . '/auditTo.php';

        $this->curlHelper->curlOut([
            'agent'   => $assetKey, // todo auditTo.php does not appear to use the agent parameter
            'code'    => $code,
            'message' => $message,
            'time'    => $time
        ], $endpoint);
    }

    /**
     * Writes special log codes to their respective log files.
     *
     * Snapshot messages get logged to KEYBASE/asset.snp.log and verification messages get
     * logged to KEYBASE/asset.scr.log.
     *
     * @param string $assetKey
     * @param string $code
     * @param string $message
     * @param int $time
     */
    private function handleSpecial(string $assetKey, string $code, string $message, int $time): void
    {
        $prefix = AlertCodes::getPrefix($code);
        $isScreenshotCode = in_array($prefix, self::VERIFICATION_PREFIXES, true);
        $isSnapshotCode = in_array($prefix, self::BACKUP_PREFIXES, true);

        if ($isScreenshotCode) {
            $this->screenshots->log($assetKey, $code, $message, $time);
        }
        if ($isSnapshotCode) {
            $this->snapshots->log($assetKey, $code, $message, $time);
        }

        $this->clearAlert($assetKey, $code);
    }

    /**
     * Checks to see if the asset is suppressing this specific type of alert. Will return false
     * if advanced alerting is turned on.
     *
     * @param string $assetKey
     * @param string|null $type
     * @return bool
     */
    private function isAlertSuppressionActive(string $assetKey, $type = self::ALERT_SUPPRESSION_TYPE_CRITICAL): bool
    {
        $type = strtolower($type);
        if ($this->isAdvancedAlertingEnabled()) {
            return false;
        }

        $suppressionConfig = $this->getAlertSuppression($assetKey);
        $suppressedUntil = $suppressionConfig[$type] ?? 0;

        return $suppressedUntil > $this->dateTimeService->getTime();
    }

    /**
     * Gets the alert suppression config for this asset.
     *
     * @param string $assetKey
     * @return array
     */
    private function getAlertSuppression(string $assetKey): array
    {
        $agentConfig = $this->agentConfigFactory->create($assetKey);

        if ($agentConfig->has(self::ASSET_KEY_ALERT_SUPPRESSION)) {
            return unserialize($agentConfig->get(self::ASSET_KEY_ALERT_SUPPRESSION), ['allowed_classes' => false]);
        } else {
            return [];
        }
    }

    /**
     * Sets the alert suppression config for this asset.
     *
     * @param string $assetKey
     * @param array $suppressionConfig
     */
    private function setAlertSuppression(string $assetKey, array $suppressionConfig): void
    {
        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $agentConfig->set(self::ASSET_KEY_ALERT_SUPPRESSION, serialize($suppressionConfig));
    }

    /**
     * Sends out an email (critical/warning) for an alert
     *
     * @param string $assetKey
     * @param string $code Single Internal Message Code (AlertCodes)
     * @param string $message
     * @param array $context
     */
    private function sendAlertMessage(string $assetKey, string $code, string $message, array $context = null): void
    {
        $sendCriticalEmail = AlertCodes::checkCritical($code);
        $sendWarningEmail = AlertCodes::checkWarning($code) || AlertCodes::checkUrgentWarning($code);

        // Use the partnerAlertMessage, if available.
        $message = $context['partnerAlertMessage'] ?? $message;

        if ($sendCriticalEmail) {
            // Critical screenshot emails get sent to the screenshot failure email
            $prefix = AlertCodes::getPrefix($code);
            $isScreenshotCode = in_array($prefix, self::VERIFICATION_PREFIXES, true);

            $email = $this->criticalEmailGenerator->generate($assetKey, $message, $isScreenshotCode);
            $this->emailService->sendEmail($email);
        } elseif ($sendWarningEmail) {
            $email = $this->warningEmailGenerator->generate($assetKey, $message);
            $this->emailService->sendEmail($email);
        }
    }

    /**
     * Creates the backend error report (.lastError keyfile) that will be read and displayed on the agent page.
     *
     * @param string $assetKey
     * @param string $code Single Internal Message Code (AlertCodes)
     * @param string $message
     * @param array|null $context
     */
    private function createErrorReport(string $assetKey, string $code, string $message, $context): void
    {
        $now = $this->dateTimeService->getTime();
        $deviceID = (int)$this->deviceConfig->get('deviceID');
        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $agentData = unserialize($agentConfig->get('agentInfo'), ['allowed_classes' => false]);
        $log = $this->getLogLines($assetKey, static::LOG_LINES);

        $error = new LastErrorAlert(
            $assetKey,
            $deviceID,
            $now,
            $agentData,
            $code,
            $now,
            $message,
            $message,
            $log,
            $context
        );

        $agentConfig->set('lastError', $this->lastErrorSerializer->serialize($error));
    }

    /**
     * Removes an alert from the asset's lastError report.
     *
     * @param string $assetKey
     * @param string $code
     */
    private function clearError(string $assetKey, string $code): void
    {
        $agentConfig = $this->agentConfigFactory->create($assetKey);

        $lastError = $this->lastErrorSerializer->unserialize($agentConfig->get('lastError', null));
        $lastCode = $lastError ? $lastError->getCode() : null;

        if ($code === $lastCode) {
            $agentConfig->clear('lastError');
        }
    }

    /**
     * Gets the last N lines from the asset's log file.
     *
     * @param string $assetKey
     * @param int $lines
     * @return string
     */
    private function getLogLines(string $assetKey, int $lines): string
    {
        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $logContents = $agentConfig->get('log');
        $logLines = explode(PHP_EOL, $logContents);

        $lastLines = array_slice($logLines, -1 * $lines);

        return implode(PHP_EOL, $lastLines);
    }

    /**
     * Adds an alert to an asset.
     *
     * @param string $assetKey
     * @param Alert $alert
     */
    private function addAlert(string $assetKey, Alert $alert): void
    {
        $alerts = $this->getAllAlerts($assetKey);

        $alerts[] = $alert;

        $this->setAlerts($assetKey, $alerts);
    }

    /**
     * Overwrites the current list of active alerts for this asset with a new list.
     *
     * @param string $assetKey
     * @param Alert[] $alerts
     */
    private function setAlerts(string $assetKey, array $alerts): void
    {
        $config = $this->agentConfigFactory->create($assetKey);

        if (!$config->has(AssetRemovalService::REMOVING_KEY)) {
            $config->set('alertConfig', $this->alertSerializer->serialize($alerts));
        }
    }
}
