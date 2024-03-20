<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Config\DeviceConfig;
use Datto\Log\DeviceLoggerInterface;
use Datto\Resource\DateTimeService;

/**
 * Ensure that the service status doesn't contain conflicting keys that may have been persisted by an incorrect
 * billing update.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class UpdateInvalidServiceStatus implements ConfigRepairTaskInterface
{
    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct(
        DeviceConfig $deviceConfig,
        DateTimeService $dateTimeService,
        DeviceLoggerInterface $logger
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->dateTimeService = $dateTimeService;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        $updatedConfig = false;
        $serviceStatusJSON = $this->deviceConfig->get('serviceStatus', '');
        $serviceStatus = json_decode($serviceStatusJSON, true);

        if (!isset(
            $serviceStatus['isInfiniteRetention'],
            $serviceStatus['hasAppliedInfiniteRetentionConfiguration'],
            $serviceStatus['hasAppliedInfiniteRetentionExpiredConfiguration']
        )) {
            // We don't have the necessary information to make any corrections to the serviceStatus file
            return false;
        }

        if ($serviceStatus['isInfiniteRetention'] === false &&
            $serviceStatus['hasAppliedInfiniteRetentionConfiguration'] === true &&
            $serviceStatus['hasAppliedInfiniteRetentionExpiredConfiguration'] === false) {
            // Not infinite retention, but applied ICR config
            $serviceStatus['hasAppliedInfiniteRetentionConfiguration'] = false;
            $this->deviceConfig->set('serviceStatus', json_encode($serviceStatus));
            $updatedConfig = true;
        } elseif ($serviceStatus['isInfiniteRetention'] === false &&
            $serviceStatus['hasAppliedInfiniteRetentionConfiguration'] === true &&
            $serviceStatus['hasAppliedInfiniteRetentionExpiredConfiguration'] === true) {
            // Not infinite retention, but both flags are true
            // Assume that we transitioned from ICR to non-ICR
            $serviceStatus['hasAppliedInfiniteRetentionConfiguration'] = false;
            $this->deviceConfig->set('serviceStatus', json_encode($serviceStatus));
            $updatedConfig = true;
        } elseif ($serviceStatus['isInfiniteRetention'] === true &&
            $serviceStatus['hasAppliedInfiniteRetentionConfiguration'] === true &&
            $serviceStatus['hasAppliedInfiniteRetentionExpiredConfiguration'] === true &&
            isset($serviceStatus['infiniteRetentionDate']) &&
            $serviceStatus['infiniteRetentionDate'] > $this->dateTimeService->getTime()) {
            // Infinite retention, not expired yet, but both flags are true
            $serviceStatus['hasAppliedInfiniteRetentionExpiredConfiguration'] = false;
            $this->deviceConfig->set('serviceStatus', json_encode($serviceStatus));
            $updatedConfig = true;
        }

        return $updatedConfig;
    }
}
