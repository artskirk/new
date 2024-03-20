<?php

namespace Datto\Billing;

use Datto\Asset\Asset;
use Datto\Cloud\JsonRpcClient;
use Datto\Config\AgentConfigFactory;
use Datto\Log\LoggerFactory;
use Datto\System\Storage\StorageService;
use Datto\ZFS\ZfsDatasetService;
use Exception;
use Datto\Config\DeviceConfig;
use Datto\Asset\Retention;
use Datto\Resource\DateTimeService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Service to provide locally stored billing information, e.g. the service
 * expiration date. Uses >0 expiration and > DAYS_BETWEEN_EXPIRY_AND_OUT_OF_SERVICE out of service logic.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 * @author Philipp Heckel <pheckel@datto.com>
 * @author Mike Micatka <mmicatka@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class Service
{
    /** @var int */
    const DAYS_BETWEEN_EXPIRY_AND_OUT_OF_SERVICE = 30;

    /** @var int */
    const DAYS_OF_INFINITE_RETENTION_GRACE_PERIOD = 30;

    /** @var int */
    const EARLIEST_EXPIRATION_DATE_IN_SECONDS = 1167609600;

    /** @var DeviceConfig */
    private $config;

    /** @var int */
    private $expirationDate;

    /** @var bool */
    private $alertSentState;

    /** @var bool */
    private $isInfiniteRetention;

    /** @var int */
    private $infiniteRetentionGracePeriodEndDate;

    /** @var int */
    private $isLocalOnly;

    /** @var JsonRpcClient */
    private $client;

    /** @var DateTimeService */
    private $timeService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var ServicePlanService */
    private $servicePlanService;

    /** @var StorageService */
    private $storageService;

    /** @var ZfsDatasetService */
    private $datasetService;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var bool */
    private $hasAppliedInfiniteRetentionConfiguration;

    /** @var bool */
    private $hasAppliedInfiniteRetentionExpiredConfiguration;

    /** @var array */
    private $originalStatus;

    public function __construct(
        DeviceConfig $config = null,
        JsonRpcClient $client = null,
        DeviceLoggerInterface $logger = null,
        DateTimeService $timeService = null,
        ServicePlanService $servicePlanService = null,
        StorageService $storageService = null,
        ZfsDatasetService $datasetService = null,
        AgentConfigFactory $agentConfigFactory = null
    ) {
        $this->config = $config ?? new DeviceConfig();
        $this->client = $client ?? new JsonRpcClient();
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
        $this->timeService = $timeService ?? new DateTimeService();
        $this->servicePlanService = $servicePlanService ?? new ServicePlanService($this->config);
        $this->storageService = $storageService ?? new StorageService();
        $this->datasetService = $datasetService ?? new ZfsDatasetService();
        $this->agentConfigFactory = $agentConfigFactory ?? new AgentConfigFactory();
        $this->loadStatus();
    }

    /**
     * Gets expiration date epoch, or null if the device is set to never expire
     */
    public function getExpirationDate()
    {
        return $this->expirationDate;
    }

    /**
     * Return the infinite retention grace period expiration date epoch, or null if we are not configured as ICR.
     */
    public function getInfiniteRetentionGracePeriodEndDate()
    {
        return $this->infiniteRetentionGracePeriodEndDate;
    }

    /**
     * Returns true if the device is on the Infinite Cloud Retention billing plan, which means offsite snapshots will
     * never be deleted, or false otherwise.  Note that a device can be configured as both infinite retention and
     * time-based retention at the same time, which is the case when transitioning from infinite cloud to time-based
     * retention (within the ICR grace period).
     */
    public function isInfiniteRetention(): bool
    {
        return $this->isInfiniteRetention;
    }

    /**
     * Returns true if we are currently in the infinite retention grace period, false otherwise
     */
    public function inInfiniteRetentionGracePeriod(): bool
    {
        $gracePeriodStartDate = $this->infiniteRetentionGracePeriodEndDate - (self::DAYS_OF_INFINITE_RETENTION_GRACE_PERIOD * DateTimeService::SECONDS_PER_DAY);

        return $this->infiniteRetentionGracePeriodEndDate &&
            $this->timeService->getTime() >= $gracePeriodStartDate &&
            $this->timeService->getTime() < $this->infiniteRetentionGracePeriodEndDate;
    }

    /**
     * Returns true if the infinite retention grace period has expired, or false if there's no end date or if we haven't
     * passed the configured infinite retention grace period end date
     */
    public function hasInfiniteRetentionGracePeriodExpired(): bool
    {
        return $this->infiniteRetentionGracePeriodEndDate && $this->infiniteRetentionGracePeriodEndDate <= $this->timeService->getTime();
    }

    /**
     * Returns true if device has been expired for more than DAYS_BETWEEN_EXPIRY_AND_OUT_OF_SERVICE days.
     * Out of Service is a concept that applies to the device, not relevant to cloud retention settings.
     */
    public function isOutOfService(): bool
    {
        // a cloud device is never out of service
        if ($this->config->isCloudDevice() || $this->config->isAzureDevice()) {
            return false;
        }

        return $this->getExpirationDays() > self::DAYS_BETWEEN_EXPIRY_AND_OUT_OF_SERVICE;
    }

    /**
     * Checks the billing status and updates the config
     * @param Asset[] $assets The assets currently on the device.
     *      Must be passed in here instead of calling $assetService->getAll() to prevent circular dependency.
     */
    public function getServiceInfo(array $assets)
    {
        $this->logger->debug('BIL0100 Checking service expiration date for device...');
        try {
            $result = $this->client->queryWithId('v1/device/billing/getServiceInfo');

            if (!$result) {
                $this->logger->warning('BIL0101 Cannot determine service plan information');
            } else {
                $result['servicePlanShortCode'] = $result['servicePlanShortCode'] ?? '';
                $result['hardwareModel'] = $result['hardwareModel'] ?? '';
                $result['servicePlanDescription'] = $result['servicePlanDescription'] ?? '';
                $result['servicePlanName'] = $result['servicePlanName'] ?? '';
                // This value is only used as a starting point for determining what the isInfiniteRetention will be persisted as locally
                $result['isInfiniteRetention'] = $result['isInfiniteRetention'] ?? false;
                $result['expiration'] = $result['expiration'] ?? null;
                $result['localOnly'] = $result['localOnly'] ?? 0;
                // left the below check the old way because of typecasting
                $result['capacity'] = isset($result['capacity']) ? (int) $result['capacity'] : 0;

                $this->logger->debug('BIL0102 Device service plan name is ' . $result['servicePlanName']);
                $this->logger->debug('BIL0103 Device service plan short code is ' . $result['servicePlanShortCode']);
                $this->logger->debug('BIL0104 Device service plan description is ' . $result['servicePlanDescription']);
                $this->logger->debug('BIL0105 Device hardware model is ' . $result['hardwareModel']);
                $this->logger->debug('BIL0106 Device infinite retention is ' . ($result['isInfiniteRetention'] ? 'true' : 'false'));
                $this->logger->debug('BIL0107 Device service expiration is ' . $result['expiration']);
                $this->logger->debug('BIL0108 Device local only is ' . ($result['localOnly'] ? 'true' : 'false'));
                $this->logger->debug('BIL0109 Device capacity is ' . $result['capacity'] . ' bytes');

                $this->writeConfigFiles(
                    $result['servicePlanShortCode'],
                    $result['hardwareModel'],
                    $result['servicePlanDescription'],
                    $result['servicePlanName'],
                    $result['capacity']
                );
                $this->isLocalOnly = (int) $result['localOnly'];
                if ($result['capacity'] > 0) {
                    $this->setZfsDatasetQuota($result['capacity']);
                }

                if (isset($result['expiration'])) {
                    // The 2 cases we need apply changes to the ICR grace period expiration date are:
                    // 1. Service plan change from ICR to something else
                    //  - In this case, we want to update the grace period end date relative to when this check happened
                    // 2. The expiration date has changed (cancellation or normal extension via task scheduler cron)
                    //  - In this case, we want to update the grace period end date relative to the service expiration date
                    if ($this->isInfiniteRetention &&
                        !$result['isInfiniteRetention'] &&
                        !$this->inInfiniteRetentionGracePeriod() &&
                        !$this->hasInfiniteRetentionGracePeriodExpired()) {
                        // Update persisted infiniteRetentionDate to current time + 30 days if changing from ICR to
                        // non-ICR service plan, and we're not already in or past the existing ICR grace period
                        // If we are transitioning from ICR to non-ICR (!$result['isInfiniteRetention']), we want to
                        // immediately enter the ICR grace period and then keep the grace period date consistent until
                        // the grace period is over.
                        $this->infiniteRetentionGracePeriodEndDate = $this->timeService->getTime() + (self::DAYS_OF_INFINITE_RETENTION_GRACE_PERIOD * DateTimeService::SECONDS_PER_DAY);
                    } elseif (($result['expiration'] !== $this->expirationDate || !$this->isInfiniteRetention)
                        && $result['isInfiniteRetention']) {
                        // Update persisted infiniteRetentionDate to service expiration date + 30 days when the
                        // expiration date is updated by cancellation (or gets pushed forward) or we were previously on a
                        // non-ICR plan AND the DB tells us we are now on an ICR plan.
                        // This way, if we cancel and just let it expire (no expiration date change), we will not
                        // change the grace period end date, and the partner will still get 30 days of grace period
                        // after the service expiration date
                        $this->infiniteRetentionGracePeriodEndDate = (int) $result['expiration'] + (self::DAYS_OF_INFINITE_RETENTION_GRACE_PERIOD * DateTimeService::SECONDS_PER_DAY);
                    }
                    $this->expirationDate = (int) $result['expiration'];
                    $this->logOutOfServiceStatusChange();
                } else {
                    $this->logger->warning('BIL0111 Cannot determine service expiration');
                }

                // We can turn ON infinite retention at any time, so changing the persisted value to true can always
                // be done immediately.  If we want to turn OFF infinite retention once it was previously ON,
                // (transition to TBR or other), we have to wait until after the ICR grace period has expired.  If the
                // initial registration was done with TBR or other, that's another case where we could set it to OFF.
                // Description         |Local InfiniteRetention|Incoming InfiniteRetention|GracePeriodExpired|Result
                // --------------------|-----------------------|--------------------------|------------------|------
                // ICR Canceled        |          True         |           True           |      True        | True
                // Configured as ICR   |          True         |           True           |      False       | True
                // ICR=>TBR, after GP  |          True         |           False          |      True        | False
                // ICR=>TBR, in GP     |          True         |           False          |      False       | True
                // Invalid(TBR=>ICR, GP not updated)|  False   |           True           |      True        | True
                // Initial reg ICR     |          False        |           True           |      False       | True
                // Invalid(post ICR=>TBR, GP not updated)|False|           False          |      True        | True
                // Initial register TBR|          False        |           False          |      False       | False
                $this->isInfiniteRetention = $result['isInfiniteRetention'] || ($this->isInfiniteRetention &&
                    !(!$result['isInfiniteRetention'] && $this->hasInfiniteRetentionGracePeriodExpired()));

                if (isset($result['timeBasedRetentionYears'])) {
                    $this->setTimeBasedRetentionYears((int) $result['timeBasedRetentionYears']);
                } else {
                    $this->logger->debug('BIL00112 Billing endpoint did not return timeBasedRetentionYears, skipping.');
                }

                $this->updateAssetRetentionSettings($assets);
            }
        } catch (Exception $e) {
            $this->logger->critical('BIL0113 Unable to get service information from device web', ['exception' => $e]);
        } finally {
            $this->persistStatus();
        }
    }

    /**
     * Returns true if the device is on the time-based retention plan, false otherwise.  Note that a device can be
     * configured as both infinite retention and time-based retention at the same time, which is the case when
     * transitioning from infinite cloud to time-based retention (within the ICR grace period).
     */
    public function isTimeBasedRetention(): bool
    {
        return $this->getTimeBasedRetentionYears() != 0;
    }

    /**
     * Get the number of years for time based data retention. If it returns is 0, TBR is disabled.
     * @return int The number of years for time based data retention
     */
    public function getTimeBasedRetentionYears(): int
    {
        return (int) $this->config->get('timeBasedRetentionYears', 0);
    }

    /**
     * Whether or not a device is local only and should not be able to offsite points
     */
    public function isLocalOnly(): bool
    {
        return (bool) $this->isLocalOnly;
    }

    /**
     * Returns true if device has been expired for more than 0 days
     */
    private function isExpired(): bool
    {
        return $this->getExpirationDays() > 0;
    }

    /**
     * Set the devices ZFS quota if applicable.
     *
     * @param int $targetQuotaInBytes
     */
    private function setZfsDatasetQuota(int $targetQuotaInBytes)
    {
        $currentQuota = $this->storageService->getDatasetQuota();
        $isQuotaUpdatedRequired = $currentQuota !== $targetQuotaInBytes;
        if (!$isQuotaUpdatedRequired) {
            $this->logger->debug("BIL0015 Quota already set to $targetQuotaInBytes");
            return;
        }

        $dataset = $this->datasetService->getDataset(StorageService::DEFAULT_QUOTA_DATASET);
        $datasetUsed = $dataset->getUsedSpace();
        if ($datasetUsed >= $targetQuotaInBytes) {
            $this->logger->warning("BIL0016 Current used size is greater than target quota. Quota will not be applied");
            return;
        }

        $this->logger->info('BIL0017 Current used size is less than target quota. Quota will be updated.', ['targetQuotaInBytes' => $targetQuotaInBytes]);
        $this->storageService->setDatasetQuota($targetQuotaInBytes, StorageService::DEFAULT_QUOTA_DATASET);
    }

    private function setTimeBasedRetentionYears(int $timeBasedRetentionYears)
    {
        $this->config->set('timeBasedRetentionYears', $timeBasedRetentionYears);
    }

    /**
     * Logs a message when the device goes out of service or comes back into service
     */
    private function logOutOfServiceStatusChange()
    {
        if ($this->isOutOfService()) {
            if (!$this->alertSentState) {
                $this->logger->info('BIL0002 All alerts are now disabled as this device has just gone out of service.');
                $this->alertSentState = true;
            }
        } else {
            if ($this->alertSentState) {
                $this->logger->info('BIL0020 Device in service, resetting alert flag, alerts now enabled again.');
                $this->alertSentState = false;
            }
        }
    }

    /**
     * Sets the offsite retention configuration per asset.  Must be called when transitioning to a service plan so that
     * assets are set to use the correct retention settings (ICR or TBR), and also after ICR grace period has expired so
     * that the retention settings can be configured to something other than "never" in order to allow offsited data to
     * be cleaned up
     */
    private function updateAssetRetentionSettings(array $assets)
    {
        // We could be both infinite and time based retention if we are transitioning from ICR to TBR, and we are
        // still in the infinite retention grace period
        $isTimeBasedRetention = $this->isTimeBasedRetention();
        // This is the infinite retention value that we are transitioning to, not necessarily exactly what came from the database
        $isInfiniteRetention = $this->isInfiniteRetention();
        $hasInfiniteRetentionExpired = $this->hasInfiniteRetentionGracePeriodExpired();
        $transitionedFromICRToDifferentService = $this->originalStatus['isInfiniteRetention'] && !$isInfiniteRetention;
        $canceledICRService = $isInfiniteRetention && $hasInfiniteRetentionExpired;

        // Apply infinite retention if we're ICR, not expired, and we haven't previously applied ICR
        $shouldApplyInfiniteRetention = !$isTimeBasedRetention && $isInfiniteRetention
            && !$hasInfiniteRetentionExpired && !$this->hasAppliedInfiniteRetentionConfiguration;
        // Set special ICR config if service has been entirely canceled or we're transitioning to a non-ICR, non-TBR
        // service plan, and we haven't already applied the special grace period expired ICR settings.
        $shouldApplyExpiredInfiniteRetention = !$isTimeBasedRetention &&
            ($canceledICRService || $transitionedFromICRToDifferentService)
            && !$this->hasAppliedInfiniteRetentionExpiredConfiguration;
        // Set time based retention configuration if we are doing initial registration with TBR, or we are transitioning
        // from ICR to TBR and we are after the ICR grace period has ended
        $shouldApplyTimeBasedRetention = $isTimeBasedRetention
            && (!$isInfiniteRetention || !$this->infiniteRetentionGracePeriodEndDate || $hasInfiniteRetentionExpired);

        if ($shouldApplyInfiniteRetention || $shouldApplyExpiredInfiniteRetention || $shouldApplyTimeBasedRetention) {
            // These state variables should be set whether we have assets on this device or not
            $this->hasAppliedInfiniteRetentionConfiguration = $shouldApplyInfiniteRetention;
            $this->hasAppliedInfiniteRetentionExpiredConfiguration = $shouldApplyExpiredInfiniteRetention && !$shouldApplyInfiniteRetention;
            // Set the offsite retention defaults for each asset if we are transitioning between service plans, or if ICR has expired
            foreach ($assets as $asset) {
                $assetKey = $asset->getKeyName();
                $agentConfig = $this->agentConfigFactory->create($assetKey);
                $offsiteRetentionConfig = null;

                if ($shouldApplyInfiniteRetention) {
                    $this->logger->info('BIL0021 Asset will be updated to use infinite cloud retention defaults.', ['assetKey' => $assetKey]);

                    $offsiteRetentionConfig = Retention::createDefaultInfinite($this);
                } elseif ($shouldApplyExpiredInfiniteRetention) {
                    $this->logger->info('BIL0022 Asset will be updated to use expired infinite cloud retention defaults.', ['assetKey' => $assetKey]);

                    $offsiteRetentionConfig = Retention::createDefaultInfinite($this);
                } elseif ($shouldApplyTimeBasedRetention) {
                    $offsiteRetentionConfig = Retention::createTimeBased($this->getTimeBasedRetentionYears());
                    $current = $asset->getOffsite()->getRetention();

                    if (!$current->equals($offsiteRetentionConfig)) {
                        $this->logger->info('BIL0023 Asset will be updated to use time-based cloud retention.', ['assetKey' => $assetKey]);
                    } else {
                        $this->logger->info('BIL0024 Asset already set to time-based defaults.', ['assetKey' => $assetKey]);
                        $offsiteRetentionConfig = null;
                    }
                }

                if ($offsiteRetentionConfig) {
                    $agentConfig->set('offsiteRetention', sprintf(
                        '%s:%s:%s:%s',
                        $offsiteRetentionConfig->getDaily(),
                        $offsiteRetentionConfig->getWeekly(),
                        $offsiteRetentionConfig->getMonthly(),
                        $offsiteRetentionConfig->getMaximum()
                    ));
                } else {
                    $this->logger->info('BIL0025 Asset already set to the correct retention configuration, not updating', ['assetKey' => $assetKey]);
                }
            }
        } else {
            $this->logger->info("BIL0026 All assets already set to the correct retention configuration, not updating");
        }
    }

    /**
     * Write config files for servicePlanInfo and hardwareModel
     *
     * Will add the isSnapNas flag if DNAS, otherwise will remove the flag
     */
    private function writeConfigFiles(
        string $billingID,
        string $hardwareModel,
        string $description,
        string $planName,
        int $quotaCapacity
    ) {
        $servicePlan = new ServicePlan(strtolower($planName), strtolower($billingID), strtolower($description), $quotaCapacity);
        $this->servicePlanService->save($servicePlan);

        // CP-8815. make the device a DNAS if it should be
        $productModel = $this->config->get('model', '');
        if (strpos($productModel, 'DN') === 0) {
            $this->config->set('isSnapNAS', ' ');
        }

        if ($billingID) {
            if (strpos($billingID, 'DN') !== false) {
                // If this space isn't there, it will not write the config
                $this->config->set('isSnapNAS', ' ');
            } elseif (strpos($billingID, 'S') !== false) {
                $this->config->clear('isSnapNAS');
            }
        }

        $this->config->set('hardwareModel', $hardwareModel);
    }

    /**
     * Returns number of days the device has been expired
     */
    private function getExpirationDays(): int
    {
        $expirationDate = $this->getExpirationDate();
        $serviceNeverExpires = $expirationDate === null;
        // Assume the earliest possible expiration date to be 2007-01-01 00:00:00
        $invalidExpiration = $expirationDate < self::EARLIEST_EXPIRATION_DATE_IN_SECONDS;

        if ($serviceNeverExpires || $invalidExpiration) {
            return 0;
        }

        // Add a 24 hour buffer to the current time allow for timezone differences
        // then deduct the out of service timestamp so see if we have any time left
        $hasBeenExpiredForSeconds = ($this->timeService->getTime() - DateTimeService::SECONDS_PER_DAY) - $expirationDate;
        $hasService = $hasBeenExpiredForSeconds < 0;
        if ($hasService) {
            return 0;
        }

        // Convert seconds to days, round up (if it's expired by 1 second, we want expiration of 1 day)
        return (int) ceil($hasBeenExpiredForSeconds / DateTimeService::SECONDS_PER_DAY);
    }

    /**
     * Saves expiration status and infinite cloud retention configuration to our local serviceStatus file
     */
    private function persistStatus()
    {
        $status = $this->formatStatus();

        $this->config->set('serviceStatus', json_encode($status));
    }

    /**
     * Loads expiration status and if it is infinite cloud retention or not from the local serviceStatus file
     */
    private function loadStatus()
    {
        $statusJSON = $this->config->get('serviceStatus', '');
        $status = json_decode($statusJSON, true);
        $this->expirationDate = isset($status['expirationDate']) ? (int) $status['expirationDate'] : null;
        $this->alertSentState = isset($status['alertSentState']) && $status['alertSentState'];
        $this->isInfiniteRetention = isset($status['isInfiniteRetention']) && $status['isInfiniteRetention'];
        $this->infiniteRetentionGracePeriodEndDate = isset($status['infiniteRetentionDate']) ? (int) $status['infiniteRetentionDate'] : null;
        $this->isLocalOnly = isset($status['isLocalOnly']) ? (int) $status['isLocalOnly'] : 0;
        $this->hasAppliedInfiniteRetentionConfiguration = $status['hasAppliedInfiniteRetentionConfiguration'] ?? false;
        $this->hasAppliedInfiniteRetentionExpiredConfiguration = $status['hasAppliedInfiniteRetentionExpiredConfiguration'] ?? false;

        $this->originalStatus = $this->formatStatus();
    }

    /**
     * Put each property that will be persisted in the serviceStatus file into an array
     * @return array containing each property to go in the serviceStatus file
     */
    private function formatStatus(): array
    {
        $status = [
            'expirationDate' => $this->expirationDate,
            'alertSentState' => $this->alertSentState,
            'isInfiniteRetention' => $this->isInfiniteRetention,
            'infiniteRetentionDate' => $this->infiniteRetentionGracePeriodEndDate,
            'isLocalOnly' => $this->isLocalOnly,
            'hasAppliedInfiniteRetentionConfiguration' => $this->hasAppliedInfiniteRetentionConfiguration,
            'hasAppliedInfiniteRetentionExpiredConfiguration' => $this->hasAppliedInfiniteRetentionExpiredConfiguration
        ];

        return $status;
    }
}
