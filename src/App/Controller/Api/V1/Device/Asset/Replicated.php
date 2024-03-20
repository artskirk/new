<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\AssetService;
use Datto\Replication\AssetMetadata;
use Datto\Replication\ReplicationService;
use Datto\Replication\SpeedSyncAuthorizedKeysService;
use Datto\Service\Reporting\Zfs\ZfsCorruptionDataService;
use Datto\ZFS\ZpoolService;

/**
 * Perform actions to set up and modify assets that are being replicated
 * from other devices.
 *
 * @author John Roland <jroland@datto.com>
 */
class Replicated
{
    /** @var SpeedSyncAuthorizedKeysService */
    private $speedsyncAuthorizedKeysService;

    /** @var ReplicationService */
    private $replicationService;

    /** @var AssetService */
    private $assetService;

    /** @var ZpoolService */
    private $zpoolService;

    /** @var ZfsCorruptionDataService */
    private $zfsCorruptionDataService;

    public function __construct(
        SpeedSyncAuthorizedKeysService $speedsyncAuthorizedKeysService,
        ReplicationService $replicationService,
        AssetService $assetService,
        ZpoolService $zpoolService,
        ZfsCorruptionDataService $zfsCorruptionDataService
    ) {
        $this->speedsyncAuthorizedKeysService = $speedsyncAuthorizedKeysService;
        $this->replicationService = $replicationService;
        $this->assetService = $assetService;
        $this->zpoolService = $zpoolService;
        $this->zfsCorruptionDataService = $zfsCorruptionDataService;
    }

    /**
     * Prepare this device to receive asset backups from another device.
     * Sets up the user and public key that SpeedSync will use to replicate the backups
     * and also the basic asset config files needed for restores and other actions.
     *
     * Example payloads:
     * "params": {
     *      "primaryDeviceId": 10529,
     *      "assetKey": "abcdef0123456789abcdef0123456789",
     *      "publicKey": "ssh-rsa ... user@someDomain",
     *      "assetMetadata": {
     *          "originDeviceId": 10529,
     *          "originResellerId": 3632,
     *          "hostname": "eis-eb3085",
     *          "type": "windows",
     *          "fqdn": "10.0.197.10",
     *          "operatingSystem": "Windows Server 2016"
     *      }
     * }
     *
     * "params": {
     *      "originDeviceId": 10529,
     *      "originResellerId": 3632,
     *      "assetKey": "abcdef0123456789abcdef0123456789",
     *      "publicKey": "ssh-rsa ... user@someDomain",
     *      "assetMetadata": {
     *          "hostname": "nas01",
     *          "type": "nas",
     *      }
     * }
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REPLICATION_TARGET")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_PROVISION")
     *
     * @param int $primaryDeviceId ID of the device this asset is coming from (not necessarily the origin)
     * @param string $assetKey UUID of the asset
     * @param string $publicKey Public key that will be used to replicate the backups via SpeedSync
     * @param array $assetMetadata Basic information about the asset (name, OS, etc.)
     * @return bool
     */
    public function provision(
        int $primaryDeviceId,
        string $assetKey,
        string $publicKey,
        array $assetMetadata
    ) {
        $assetMetadataObject = AssetMetadata::fromArray($assetKey, $assetMetadata);

        $this->replicationService->provision($primaryDeviceId, $publicKey, $assetMetadataObject);

        return true;
    }

    /**
     * No longer allow a particular asset to replicate to this machine. This method will remove
     * the asset key from the list of allowed assets for a particular public key, and if no assets
     * remain, the public key will be removed from the authorized keys file.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REPLICATION_TARGET")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_PROVISION")
     *
     * @param string $assetKey
     *
     * @return bool
     */
    public function deprovision(string $assetKey)
    {
        $asset = $this->assetService->get($assetKey);
        $this->replicationService->deprovision($asset);

        return true;
    }

    /**
     * Promote a replicated asset to a non-replicated asset. This will reconcile the asset one last time to fetch
     * the latest metadata from the source, and then remove the origin device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REPLICATION_PROMOTE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_PROVISION")
     *
     * @param string $assetKey
     *
     * @return bool
     */
    public function promote(string $assetKey): bool
    {
        $asset = $this->assetService->get($assetKey);
        $this->replicationService->promote($asset);

        return true;
    }

    /**
     * Demote an asset to be a replicated asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REPLICATION_PROMOTE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_PROVISION")
     *
     * @param int $primaryDeviceId
     * @param string $assetKey
     * @param string $publicKey
     * @param array $assetMetadata
     *
     * @return bool
     */
    public function demote(
        int $primaryDeviceId,
        string $assetKey,
        string $publicKey,
        array $assetMetadata
    ): bool {
        $assetMetadataObject = AssetMetadata::fromArray($assetKey, $assetMetadata);
        $asset = $this->assetService->get($assetKey);

        $this->replicationService->demote($primaryDeviceId, $asset, $publicKey, $assetMetadataObject);

        return true;
    }

    /**
     * Control whether corrupted assets will be allowed to replicate.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REPLICATE_CORRUPTED")
     *
     * @param bool $allowed
     *
     * @return bool
     */
    public function allowSendCorrupted(bool $allowed): bool
    {
        $this->zpoolService->setZpoolCorruptedBit($allowed);

        return true;
    }

    /**
     * Reconcile any replicated assets (inbound and outbound).
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REPLICATION_TARGET")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_PROVISION")
     *
     * @param array $assetKeys
     * @return bool
     */
    public function reconcile(array $assetKeys = null): bool
    {
        $this->replicationService->reconcileAssets(true, $assetKeys);

        return true;
    }

    /**
     * Generate speedSyncAuthorizedKeys for a given device and asset to enable access
     * for transfer.
     *
     * Example payloads:
     * "params": {
     *      "deviceId": 10529,
     *      "assetKey": "abcdef0123456789abcdef0123456789",
     *      "publicKey": "ssh-rsa ... user@someDomain"
     * }
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REPLICATION_TARGET")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_PROVISION")
     */
    public function authorizeAccess(
        int $deviceId,
        string $assetKey,
        string $publicKey
    ): bool {
        // Add public key and asset
        $this->speedsyncAuthorizedKeysService->add($publicKey, $deviceId, $assetKey);

        return true;
    }

    /**
     * Remove speedSyncAuthorizedKeys entry for a given asset
     * to disable access for transfer.
     *
     * Example payloads:
     * "params": {
     *      "assetKey": "abcdef0123456789abcdef0123456789",
     * }
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REPLICATION_TARGET")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_PROVISION")
     */
    public function deauthorizeAccess(string $assetKey): bool
    {
        // remove asset from authorized keys
        $this->speedsyncAuthorizedKeysService->remove($assetKey);

        return true;
    }

    /**
     * Kick off adhoc receive of agent from source device
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REPLICATION_TARGET")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_PROVISION")
     */
    public function initiateAdhocTransfer(string $assetKey, string $originDeviceDdns): bool
    {
        $asset = $this->assetService->get($assetKey);

        $this->replicationService->adhocReceive($asset, $originDeviceDdns);

        return true;
    }

    /**
     * Check if a specific asset has encountered any corruption when sending
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REPLICATION_TARGET")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_PROVISION")
     */
    public function hasCorruption(string $assetKey): array
    {
        return $this->zfsCorruptionDataService->checkZfsDatasetCorruption($assetKey);
    }
}
