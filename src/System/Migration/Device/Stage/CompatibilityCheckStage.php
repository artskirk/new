<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\System\Api\DeviceApiClientService;
use Datto\System\Api\HttpStatusException;
use Datto\System\Migration\Context;
use Datto\System\Migration\Device\DeviceMigrationExceptionCodes;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Check that the source device is on an image that is compatible with the target device.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CompatibilityCheckStage extends AbstractMigrationStage
{
    // Devices below this version do not support the authToken parameter of Ssh::startServer().
    const MINIMUM_SOURCE_VERSION = 1467;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DeviceApiClientService */
    private $deviceClient;

    /** @var DeviceConfig */
    private $deviceConfig;

    public function __construct(
        Context $context,
        DeviceLoggerInterface $logger,
        DeviceApiClientService $deviceClient,
        DeviceConfig $deviceConfig
    ) {
        parent::__construct($context);

        $this->logger = $logger;
        $this->deviceClient = $deviceClient;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $this->checkImageVersion();
        $this->checkPeerReplication();
    }

    /**
     * Ensure the source device is on a compatible image version
     */
    private function checkImageVersion()
    {
        $targetImage = $this->deviceConfig->getImageVersion();
        try {
            $deviceInfo = $this->deviceClient->call('v1/device/get');
            // If image version not present, then device pre-dates addition of this check.
            $sourceImage = $deviceInfo['imageVersion'] ?? 0;
        } catch (HttpStatusException $exception) {
            // If the endpoint doesn't exist, then the device is REALLY old
            $this->logger->warning('MIG1100 Getting target device version failed', ['exception' => $exception]);
            $sourceImage = 0;
        } catch (Throwable $throwable) {
            $this->logger->error('MIG1101 Compatibility check error', ['exception' => $throwable]);
            throw new Exception("Unable to determine source device compatibility");
        }

        if ($targetImage < $sourceImage) {
            $this->logger->error('MIG1102 Source device image is newer than target device image', ['sourceImage' => $sourceImage, 'targetImage' => $targetImage]);
            throw new Exception('Target device software is out of date');
        }

        if ($sourceImage < self::MINIMUM_SOURCE_VERSION) {
            $this->logger->error(
                'MIG1106 Source device image must not be lower than the minimum version.',
                ['sourceImageVersion' => $sourceImage, 'minimumVersion' => self::MINIMUM_SOURCE_VERSION]
            );
            throw new Exception(
                'Source device image must not be lower than the minimum version',
                DeviceMigrationExceptionCodes::MINIMUM_SOURCE_VERSION
            );
        }
    }

    /**
     * Ensure the source device does not support peer replication. Device migration is unable to handle peer replicated
     * assets at this time.
     */
    private function checkPeerReplication()
    {
        try {
            $params = ['feature' => FeatureService::FEATURE_PEER_REPLICATION];
            $output = $this->deviceClient->call('v1/device/feature/isSupported', $params);
            $supportsPeerReplication = $output['supported'] ?? false;
            $params = [];
            $hasReplicatedAssets = $this->deviceClient->call(
                'v1/device/migrate/migrateDevice/hasReplicatedAssets',
                $params
            );
        } catch (HttpStatusException $e) {
            // If the endpoint doesn't exist, then the device is old and cannot have peer replicated assets
            $this->logger->warning('MIG1103 Getting isSupported(FEATURE_PEER_REPLICATION) failed', ['exception' => $e]);
            $supportsPeerReplication = false;
            $hasReplicatedAssets = false;
        } catch (Throwable $e) {
            $this->logger->error('MIG1104 Compatibility check error', ['exception' => $e]);
            throw new Exception("Unable to determine source device compatibility");
        }

        if ($supportsPeerReplication || $hasReplicatedAssets) {
            $this->logger->error('MIG1105 Source device supports peer replication. We cannot migrate from this device.');
            throw new Exception('Cannot perform a device migration from a device that supports peer replication.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // Nothing to do here
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        // Nothing to roll back
    }
}
