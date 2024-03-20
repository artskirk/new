<?php

namespace Datto\Util\Email;

use Datto\App\Console\Input\InputArgumentException;
use Datto\Cloud\JsonRpcClient;
use Datto\Config\ContactInfoRecord;
use Datto\Config\DeviceConfig;
use Datto\Config\ServerNameConfig;
use Datto\Curl\CurlHelper;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerFactory;
use Datto\Log\DeviceLoggerInterface;

/**
 * Class: EmailService sends emails to the device's email addresses.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class EmailService
{
    /** @var ServerNameConfig */
    private $serverNameConfig;

    /** @var CurlHelper */
    private $curlHelper;

    /** @var JsonRpcClient */
    private $client;

    /** @var DeviceConfig */
    private $deviceConfig;

    /**
     * @var FeatureService
     */
    private $featureService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param ServerNameConfig|null $serverNameConfig
     * @param CurlHelper|null $curlHelper
     * @param JsonRpcClient|null $client
     * @param DeviceConfig|null $deviceConfig
     * @param DeviceLoggerInterface|null $logger
     * @param FeatureService|null $featureService
     */
    public function __construct(
        ServerNameConfig $serverNameConfig = null,
        CurlHelper $curlHelper = null,
        JsonRpcClient $client = null,
        DeviceConfig $deviceConfig = null,
        DeviceLoggerInterface $logger = null,
        FeatureService $featureService = null
    ) {
        $this->serverNameConfig = $serverNameConfig ?: new ServerNameConfig();
        $this->curlHelper = $curlHelper ?: new CurlHelper();
        $this->client = $client ?: new JsonRpcClient();
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->featureService = $featureService ?: new FeatureService();
    }

    /**
     * Send an email via sendNotice4.php on device-web.
     *
     * @param Email $email
     * @return bool
     */
    public function sendEmail(Email $email): bool
    {
        if (!$email->getRecipients()
            && !$this->deviceConfig->isCloudDevice()
            && !$this->deviceConfig->isAzureDevice()) {
            $this->logger->info("EMA0023 Not sending email because there are no recipients.");
            return false;
        }

        if ($this->featureService->isSupported(FeatureService::FEATURE_ALERTVIAJSONRPC)) {
            $this->logger->debug("EMA0025 Not sending email because device web jsonrpc alerts are enabled.");
            return false;
        }

        if ($this->deviceConfig->isCloudDevice() || $this->deviceConfig->isAzureDevice()) {
            $toEmail = $this->getDeviceEmailRecord();
            $this->logger->debug("EMA0022 Using default device email from contact info.");
        } else {
            $toEmail = $email->getRecipients();
        }

        $messageArray['to'] = $toEmail;
        $messageArray['subject'] = $email->getSubject();
        $messageArray['info'] = $email->getMessage();
        $messageArray['files'] = $email->getFiles();
        $messageArray['meta'] = $email->getMeta();
        $messageArray[JsonRpcClient::X_REQUEST_ID] = $this->logger->getContextId();

        $out = serialize($messageArray);
        $serverHostName = $this->serverNameConfig->getServer('DEVICE_DATTOBACKUP_COM');
        $url = 'https://' . $serverHostName . '/sirisReporting/sendNotice4.php';

        // todo: check if we can use email instead of curlOut
        $results = $this->curlHelper->curlOut($out, $url);

        $sent = stripos(strtoupper($results), 'QUEUED') !== false;
        return $sent;
    }

    /**
     * Checks whether provided email string contains valid emails.
     * Works with both comma-separated email list as well as single email.
     *
     * @param string $emails
     *  A comma-separated string of emails to validate.
     * @return boolean
     *  If one of the emails is invalid, returns FALSE
     */
    public static function containsValidEmailAddresses($emails): bool
    {
        // Get rid of white-space
        $emails = preg_replace('/\s/', '', $emails);
        $emails = explode(',', $emails);

        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $email
     */
    public function setDeviceAlertsEmail(string $email)
    {
        try {
            $this->assertValidEmailAddress($email);
            $contactInformation = ['contactInformation' => ['alertEmail' => $email]];
            $this->client->queryWithId('v1/device/contactInformation/setDeviceContactInformation', $contactInformation);
            $this->setEmailAddressInContactInfoRecord($email);
        } catch (\Throwable $e) {
            $this->logger->warning('EMA0001 Unable to set device alerts emails', ['exception' => $e]);
            throw $e;
        }

        $this->logger->debug("EMA0002 Device alerts email set to $email.");
    }

    /**
     * @param bool $overwrite
     *      Whether or not to overwrite the devices contact info file with what is set in the cloud
     * @return string|null
     */
    public function pullDevicePrimaryContactEmailFromCloud(bool $overwrite = false)
    {
        $email = null;
        try {
            $result = $this->client->queryWithId('v1/device/contactInformation/getDeviceContactInformation');
            if (isset($result['primary']['email'])) {
                $email = $result['primary']['email'];
                if ($overwrite) {
                    $this->logger->debug("EMA0006 Overwriting local device email with email from cloud.");
                    $this->setEmailAddressInContactInfoRecord($email);
                }
            } else {
                $this->logger->debug("EMA0004 Device alert email empty in cloud.");
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $this->logger->warning('EMA0005 Unable to pull device primary contact email', ['exception' => $message]);
            throw $e;
        }

        return $email;
    }

    /**
     * @param string $email
     */
    private function setEmailAddressInContactInfoRecord(string $email)
    {
        $existingRecord = new ContactInfoRecord();
        $this->deviceConfig->loadRecord($existingRecord);
        $existingRecord->setEmail($email);
        $this->deviceConfig->saveRecord($existingRecord);
    }

    /**
     * @param string $email
     */
    private function assertValidEmailAddress(string $email)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InputArgumentException("Invalid email address: $email");
        }
    }

    /**
     * @return string|null
     */
    private function getDeviceEmailRecord()
    {
        $existingRecord = new ContactInfoRecord();
        $this->deviceConfig->loadRecord($existingRecord);
        $email = $existingRecord->getEmail();

        if ($email === null) {
            $this->logger->warning("EMA0024 Device does not have a global email.");
        }

        return $email;
    }
}
