<?php

namespace Datto\System\Migration\Device;

use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Cloud\JsonRpcClient;
use Datto\Device\Serial;
use Datto\Network\AccessibleDevice;
use Datto\Service\Offsite\OffsiteServerService;
use Datto\System\Api\DeviceApiClientService;
use Datto\System\Migration\Device\Stage\DeviceConfigStage;
use Datto\System\Migration\MigrationService;
use Datto\System\Migration\MigrationType;
use Datto\Resource\DateTimeService;
use DateTime;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Service for Device Migrations - handles migration specific interactions
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class DeviceMigrationService
{
    const REMOTE_CALL_TIMEOUT = 10;

    /** @var DeviceApiClientService */
    private $deviceClientService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var MigrationService */
    private $migrationService;

    /** @var JsonRpcClient */
    private $deviceWebClient;

    /** @var Serial */
    private $serial;

    /** @var AssetService */
    private $assetService;

    /** @var OffsiteServerService */
    private $offsiteServerService;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct(
        DeviceApiClientService $deviceClientService,
        DateTimeService $dateTimeService,
        MigrationService $migrationService,
        JsonRpcClient $deviceWebClient,
        AssetService $assetService,
        OffsiteServerService $offsiteServerService,
        DeviceLoggerInterface $logger,
        Serial $serial
    ) {
        $this->deviceClientService = $deviceClientService;
        $this->dateTimeService = $dateTimeService;
        $this->migrationService = $migrationService;
        $this->deviceWebClient = $deviceWebClient;
        $this->assetService = $assetService;
        $this->offsiteServerService = $offsiteServerService;
        $this->logger = $logger;
        $this->serial = $serial;
    }

    /**
     * Load list of devices on local network via device-web
     *
     * @return AccessibleDevice[]
     */
    public function getDeviceList(): array
    {
        $result = $this->deviceWebClient->query('v1/device/listlocal');

        $list = json_decode($result, true);
        if (!is_array($list)) {
            throw new Exception('Invalid device list.');
        }
        $filteredList = array_filter($list, function ($e) {
            return strtolower($e['serial']) !== strtolower($this->serial->get());
        });

        $deviceList = [];
        foreach ($filteredList as $device) {
            $deviceList[] = new AccessibleDevice(
                $device['hostname'],
                $device['ip'],
                $device['model'],
                $device['serial'],
                $device['ddnsDomain'],
                $device['serviceName']
            );
        }

        return $deviceList;
    }

    /**
     * Connect to the specified device and authenticate.
     *
     * @param string $ip IP address of the remote device
     * @param string $username
     * @param string $password
     * @param string|null $ddnsDomain Dynamic DNS domain for HTTPS
     * @param string|null $sshIp the IP to use for bulk data transfers over SSH
     */
    public function connect(
        string $ip,
        string $username,
        string $password,
        string $ddnsDomain = null,
        string $sshIp = null
    ) {
        $this->deviceClientService->connect(
            $ip,
            $username,
            $password,
            $ddnsDomain,
            $sshIp
        );
    }

    /**
     * Disconnect from the current device and delete all connection information.
     */
    public function disconnect()
    {
        $this->deviceClientService->disconnect();
    }

    /**
     * Gets the asset information for the specified SIRIS device.
     *
     * @return array Array of assets that may be selected for migration.
     *    Each entry is an associative array with the following keys:
     *      'keyName':      The asset key name
     *      'name:          The asset name
     *      'displayName':  The asset display name
     *      'isReplicated': True if the asset is replicated, false if not
     *      'isShare':      True if the asset is a share, false if not.
     *                      This key may not exist when migrating from older
     *                      devices, so the caller must handle that case.
     *      'localUsed':    The dataset used size in bytes.
     *                      This key may not exist when migrating from older
     *                      devices, so the caller must handle that case.
     *      'hasMountConflict': True if the share's mount point matches an existing share, false if not
     */
    public function getAssetList(): array
    {
        $method = 'v1/device/migrate/migrateDevice/getDeviceAssets';

        $params = [];

        /** @var string[][] $assetList */
        $assetList =  $this->deviceClientService->call($method, $params, self::REMOTE_CALL_TIMEOUT);

        $filteredAssetList = array_values(array_filter($assetList, function ($asset) {
            return !$this->assetService->exists($asset['keyName']);
        }));

        $filteredAssetList = $this->addHasMountConflictField($filteredAssetList);
        // If this is an incremental migration, then the "isShare" key MUST
        // exist or the migration cannot be performed.

        if ($filteredAssetList && !$this->isDeviceClean() && !array_key_exists('isShare', $filteredAssetList[0])) {
            throw new Exception('Source device software is out of date');
        }

        return $filteredAssetList;
    }

    /**
     * Determines if a migration can be started at this time.
     * A device migration cannot be started if any migration is running,
     * including storage migrations.
     *
     * @return bool True if a migration can be started, false if not.
     */
    public function isStartAllowed(): bool
    {
        return !$this->migrationService->isRunning();
    }

    /**
     * Initiates the device migration.
     *
     * @param bool $device True to migrate the device configuration
     * @param array $assets List of asset key names to migrate
     * @param array $source Information about the source device:
     *     $source['hostname'] = hostname of the source device
     *     $source['ip'] = IP address of the source device
     */
    public function startMigration(bool $device, array $assets, array $source = [])
    {
        $targets = [];
        if ($device) {
            $targets[] = DeviceConfigStage::DEVICE_TARGET;
        }
        $targets = array_merge($targets, $assets);
        $runInBackground = true;

        $this->migrationService->schedule(
            $this->dateTimeService->getTime(),
            $source,
            $targets,
            true,
            MigrationType::DEVICE(),
            $runInBackground
        );
    }

    /**
     * Gets the current device migration status.
     *
     * @return DeviceMigrationStatus
     */
    public function getMigrationStatus(): DeviceMigrationStatus
    {
        $message = '';
        $errorCode = 0;

        $migration = $this->migrationService->getScheduled();
        if ($migration && $migration->getType() === DeviceMigration::TYPE) {
            $sources = $migration->getSources();
            $startDateTime = $migration->getScheduleAt();
            $state = DeviceMigrationStatus::STATE_RUNNING;
        } else {
            $migration = $this->migrationService->getLatestCompleted();
            if ($migration && $migration->getType() === DeviceMigration::TYPE && !$migration->isDismissed()) {
                $sources = $migration->getSources();
                $startDateTime = $migration->getScheduleAt();
                if ($migration->getStatus() === MigrationService::MIGRATION_STATUS_ERROR || $migration->hasErrorMessage()) {
                    $state = DeviceMigrationStatus::STATE_FAILED;
                    $message = $migration->getErrorMessage();
                    $errorCode = $migration->getErrorCode();
                } else {
                    $state = DeviceMigrationStatus::STATE_SUCCESS;
                }
            } else {
                $sources = [];
                $startDateTime = new DateTime();
                $state = DeviceMigrationStatus::STATE_INACTIVE;
            }
        }

        return new DeviceMigrationStatus(
            $sources['hostname'] ?? '',
            $startDateTime,
            $state,
            $message,
            $errorCode
        );
    }

    /**
     * Get the list of NICs and their configuration
     *
     * @return array
     */
    public function getNetworkList(): array
    {
        $method = 'v1/device/settings/network/getLinks';
        $params = [];

        return $this->deviceClientService->call($method, $params, self::REMOTE_CALL_TIMEOUT);
    }

    /**
     * Determines if the current destination device is clean of any assets.
     * This will allow device configuration migration.
     *
     * @return bool True if device has no assets and is considered "new"
     */
    public function isDeviceClean(): bool
    {
        return count($this->assetService->getAllKeyNames()) == 0;
    }

    /**
     * Determines if the source device off-site storage node is the same as this
     * device's (the destination device) storage node.  This is required for migration
     * to devices with existing assets.
     *
     * @returns bool true if co-located, false if not
     */
    public function isOffsiteColocated(): bool
    {
        $method = 'V1/Device/Offsite/Server/getServerAddress';

        $params = [];

        try {
            $sourceNode = $this->deviceClientService->call($method, $params, self::REMOTE_CALL_TIMEOUT);
        } catch (Throwable $exception) {
            $message = "Could not determine source off-site node. Please update the source device.";
            $this->logger->error('MIG0031 Could not determine source off-site node. Update the source device.', [
                'exception' => $exception
            ]);
            throw new Exception($message);
        }

        $destinationNode = $this->offsiteServerService->getServerAddress();
        return $sourceNode === $destinationNode;
    }

    /**
     * Adds the 'hasMountConflict' field to each asset array in the asset list returned by getDeviceAssets
     * We cannot migrate a share that has the same name as an existing share because the
     * mountpoint/iscsi target name would conflict.
     *
     * @param array $assetList
     * @return array asset list with added field
     */
    private function addHasMountConflictField(array $assetList): array
    {
        $existingShares = $this->assetService->getAll(AssetType::SHARE);

        return array_map(function ($asset) use ($existingShares) {
            $asset['hasMountConflict'] = false;
            foreach ($existingShares as $existingShare) {
                if ($asset['name'] === $existingShare->getName()) {
                    $asset['hasMountConflict'] = true;
                    break;
                }
            }
            return $asset;
        }, $assetList);
    }
}
