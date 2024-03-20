<?php

namespace Datto\App\Controller\Api\V1\Device\Migrate;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Network\AccessibleDevice;
use Datto\System\Migration\Device\DeviceMigrationService;
use Exception;
use Datto\Utility\ByteUnit;

/**
 * API for Device Migration Wizard.
 *
 * @author Chris LaRosa <clarosa@datto.com>
 */
class MigrateDevice
{
    /** @var DeviceMigrationService */
    private $migrationService;

    /** @var AssetService */
    private $assetService;

    public function __construct(
        DeviceMigrationService $migrationService,
        AssetService $assetService
    ) {
        $this->migrationService = $migrationService;
        $this->assetService = $assetService;
    }

    /**
     * Gets the list of devices on the LAN that may be used as source devices.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     * @return AccessibleDevice[] Array of devices available on the local network
     */
    public function getDeviceList(): array
    {
        return $this->migrationService->getDeviceList();
    }

    /**
     * Connect to the source device and check that it's compatible.
     * Throws an exception if it's not compatible.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     * @param string $ip The selected device IP
     * @param string $username
     * @param string $password
     * @param string|null $ddnsDomain the selected device DDNS domain
     * @param string|null $sshIp the IP to use for bulk data transfers over SSH
     */
    public function connectToDevice(
        string $ip,
        string $username,
        string $password,
        string $ddnsDomain = null,
        string $sshIp = null
    ): void {
        $this->migrationService->connect(
            $ip,
            $username,
            $password,
            $ddnsDomain,
            $sshIp
        );

        // If the device has assets (not clean) then we're doing a partial migration.
        // For partial migrations, we do not co-locate the device, so check that it's co-located.
        if (!$this->migrationService->isDeviceClean() && !$this->migrationService->isOffsiteColocated()) {
            $this->migrationService->disconnect();
            throw new Exception('Cannot migrate from this device. Offsite servers are not the same.');
        }
    }

    /**
     * Gets the list of assets on the currently selected device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     * @return array Array of asset information arrays.
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
        return $this->migrationService->getAssetList();
    }

    /**
     * Initiates the device migration.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     * @param bool $device True to migrate the device configuration
     * @param array $assets List of assets to migrate
     * @param array $source Information about the source device:
     *     $source['hostname'] = hostname of the source device
     *     $source['ip'] = IP address of the source device
     */
    public function startMigration(bool $device, array $assets, array $source = []): void
    {
        $this->migrationService->startMigration($device, $assets, $source);
    }

    /**
     * Gets the current status of the device migration.
     * This function is meant to be called every few seconds to update dynamic
     * values in the web UI.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     * @return array
     */
    public function getStatus(): array
    {
        $deviceMigrationStatus = $this->migrationService->getMigrationStatus();

        return [
            'startAllowed' => $this->migrationService->isStartAllowed(),
            'status' => [
                'hostname' => $deviceMigrationStatus->getHostname(),
                'started' => $deviceMigrationStatus->getStartDateTime()->format('n/j/Y g:i A'),
                'state' => $deviceMigrationStatus->getState(),
                'message' => $deviceMigrationStatus->getMessage(),
                'errorCode' => $deviceMigrationStatus->getErrorCode()
            ]
        ];
    }

    /**
     * Device-to-device endpoint which verifies that a connection can be made.
     * This function is simply a no-op that is called when the user enters a
     * username and password to verify that a connection can be established
     * with those credentials.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     */
    public function verifyConnection(): bool
    {
        return true;
    }

    /**
     * Device-to-device endpoint which gets all migratable assets.
     * This filters out assets that currently do not support migration.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     * @return array Array of asset information arrays (unsorted).
     *    Each asset information array contains the following keys:
     *      displayName:  The asset display name
     *      name:         The asset name
     *      keyName:      The asset key name
     *      isReplicated: True if the asset is replicated, false if not
     *      isShare       True if the asset is a share, false if not
     *      localUsed     The dataset used size in bytes
     */
    public function getDeviceAssets(): array
    {
        $assets = $this->assetService->getAll();

        $result = [];
        foreach ($assets as $asset) {
            if ($asset instanceof Agent && $asset->isRescueAgent()) {
                continue;
            }

            if ($asset instanceof Agent) {
                $localUsed = ByteUnit::GIB()->toByte($asset->getUsedLocally());
            } else {
                $localUsed = $asset->getDataset()->getUsedSize();
            }

            $isShare = $asset->isType(AssetType::SHARE);
            $result[] = [
                'displayName' => $asset->getDisplayName(),
                'name' => $asset->getName(),
                'keyName' => $asset->getKeyName(),
                'isReplicated' => $asset->getOriginDevice()->isReplicated(),
                'isShare' => $isShare,
                'localUsed' => $localUsed
            ];
        }
        return $result;
    }

    /**
     * Returns true if any of an array of assets are replicated
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     * @return bool true if the device has any replicated assets
     */
    public function hasReplicatedAssets()
    {
        $assets = $this->getDeviceAssets();
        $replicatedAssets = array_filter($assets, function ($item) {
            return isset($item['isReplicated']) && $item['isReplicated'] == true;
        });
        return count($replicatedAssets) > 0;
    }

    /**
     * Get the list of NICs and their configuration on the source device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_MIGRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_MIGRATION")
     * @return array
     */
    public function getNetworkList(): array
    {
        return $this->migrationService->getNetworkList();
    }
}
