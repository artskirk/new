<?php

namespace Datto\Util\Email;

use Datto\Asset\AssetService;
use Datto\Cloud\JsonRpcClient;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\ContactInfoRecord;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Exception;
use Datto\Log\DeviceLoggerInterface;

class MasterEmailListManager
{
    const MASTER_EMAIL_CACHE_FILE = '/datto/config/masterEmailList';
    const MAX_CACHE_AGE = 604800; // One week in seconds: 60 * 60 * 24 * 7

    /** @var Filesystem */
    private $filesystem;

    /** @var AssetService*/
    private $assetService;

    /** @var JsonRpcClient */
    private $client;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DeviceConfig */
    private $deviceConfig;

    /**
     * @param Filesystem|null $filesystem
     * @param AssetService|null $assetService
     * @param JsonRpcClient|null $client
     * @param DeviceLoggerInterface|null $logger
     * @param DeviceConfig|null $deviceConfig
     */
    public function __construct(
        Filesystem $filesystem = null,
        AssetService $assetService = null,
        JsonRpcClient $client = null,
        DeviceLoggerInterface $logger = null,
        DeviceConfig $deviceConfig = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->assetService = $assetService ?: new AssetService();
        $this->client = $client ?: new JsonRpcClient();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
    }

    /**
     * Get the master email list for the device; this includes the email that the device was registered with, pertinent
     * admin and tech role emails from the database, and any additional emails that have been assigned to individual
     * agent's various notification lists. If the 'forceCacheUpdate' parameter is true, or if the master email cache
     * is out of date per MasterEmailListManager::MAX_CACHE_AGE, we re-validate the cache by calling out to device-web
     * and reading emails from all device assets.
     *
     * @param bool|null $forceCacheUpdate Optional parameter. If this is true, we refresh the master email cache using the
     * various sources from which it is populated. Passing true guarantees the most up-to-date master list, but it
     * is an expensive operation as it has to read all agent data and make a call out to the device-web database. For
     * most use cases, the cached list should be sufficient.
     * @param int|null $currentTime Optional parameter for dependency injection.
     * @return array
     */
    public function getEmailList($forceCacheUpdate = false, $currentTime = null)
    {
        if ($forceCacheUpdate || $this->isMasterEmailCacheOutOfDate($currentTime)) {
            $emails = $this->getUpToDateDeviceEmails();
            $this->cacheMasterEmailList($emails);
        } else {
            $emails = $this->getCachedMasterEmailList();
        }
        return $emails;
    }

    /**
     * Get all emails associated with the admin or tech roles in the device-web database.
     *
     * @return string[] All emails associated with the client or the admin or tech roles of the reseller
     */
    private function getDeviceWebEmails()
    {
        try {
            $result = $this->client->queryWithId('v1/device/email/getDeviceEmails');
            if ($result) {
                return $result;
            }
        } catch (Exception $e) {
            $this->logger->error('ELM0001 Cannot retrieve email list from device-web for client/reseller email list update', ['exception' => $e]);
        }
        return array();
    }

    /**
     * Calls out to various sources to retrieve all emails associated with this device. This includes the email that
     * the device was registered with, pertinent admin and tech role emails from the database, and any additional
     * emails that have been assigned to individual agent's various notification lists.
     *
     * @return string[]
     */
    private function getUpToDateDeviceEmails()
    {
        $deviceWebEmails = $this->getDeviceWebEmails();
        $deviceAssets = $this->assetService->getAll();
        $contactInfoRecord = new ContactInfoRecord();
        $this->deviceConfig->loadRecord($contactInfoRecord);
        $deviceEmailAddress = $contactInfoRecord->getEmail();
        $deviceEmails = $deviceEmailAddress ? [$deviceEmailAddress] : [];

        foreach ($deviceAssets as $asset) {
            $emailAddressSettings = $asset->getEmailAddresses();
            $assetEmails = array_merge(
                $emailAddressSettings->getCritical(),
                $emailAddressSettings->getLog(),
                $emailAddressSettings->getNotice(),
                $emailAddressSettings->getScreenshotFailed(),
                $emailAddressSettings->getScreenshotSuccess(),
                $emailAddressSettings->getWarning(),
                $emailAddressSettings->getWeekly()
            );
            $deviceEmails = array_merge($deviceEmails, $assetEmails);
        }

        return array_values(
            array_unique(
                array_merge(
                    $deviceEmails,
                    $deviceWebEmails
                )
            )
        );
    }

    /**
     * Retrieve the master email list from the on-disk cache.
     *
     * @return string[] The master email list for the device
     */
    private function getCachedMasterEmailList()
    {
        $emailList = $this->filesystem->fileGetContents(self::MASTER_EMAIL_CACHE_FILE);
        if ($emailList !== false) {
            return @json_decode($emailList) ?: array();
        }
        return array();
    }

    /**
     * Save the list of emails to the on-disk cache.
     *
     * @param string[] $emails
     */
    private function cacheMasterEmailList($emails)
    {
        $jsonEncodedEmails = json_encode($emails);
        $this->filesystem->filePutContents(self::MASTER_EMAIL_CACHE_FILE, $jsonEncodedEmails);
    }

    /**
     * Determines whether or not the master email cache is out of date.
     *
     * @param int|null $currentTime Injectable parameter for unit testability.
     * @return bool Whether or not the master email cache is out of date.
     */
    private function isMasterEmailCacheOutOfDate($currentTime = null)
    {
        if (!$currentTime) {
            $currentTime = time();
        }
        $timestamp = $this->filesystem->exists(self::MASTER_EMAIL_CACHE_FILE) ?
            $this->filesystem->filemtime(self::MASTER_EMAIL_CACHE_FILE) : 0;
        if ($timestamp) {
            return $currentTime - $timestamp > self::MAX_CACHE_AGE;
        }
        return true;
    }
}
