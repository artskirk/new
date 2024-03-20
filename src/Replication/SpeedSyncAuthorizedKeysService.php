<?php

namespace Datto\Replication;

use Datto\Config\DeviceConfig;
use Exception;

/**
 * Manage the speedSyncAuthorizedKeys config file which maps public keys
 * to the assets they have access to.
 *
 * Format of file:
 * {
 *   "<public key 1>": {"deviceId": <deviceId 1>, "assets": ["<asset uuid 1>","<asset uuid 2>",...]},
 *   "<public key 2>": {"deviceId": <deviceId 2>, "assets": ["<asset uuid 3>","<asset uuid 4>",...]},
 * }
 *
 * @author John Roland <jroland@datto.com>
 */
class SpeedSyncAuthorizedKeysService
{
    const CONFIG_FILE_KEY = 'speedSyncAuthorizedKeys';

    const AUTHORIZED_KEY_FORMAT = 'environment="DEVICE_ID=%d",environment="SPEEDSYNC_ASSETS=%s" %s';

    /** @var DeviceConfig */
    private $deviceConfig;

    /**
     * This class will be used outside of the symfony container via an authorized_keys command
     * so we cannot assume autowiring.
     * @param DeviceConfig|null $deviceConfig
     */
    public function __construct(
        DeviceConfig $deviceConfig = null
    ) {
        $this->deviceConfig = $deviceConfig ?: new DeviceConfig();
    }

    /**
     * Add an asset to the list of authorized assets for the specified public key.
     * Public key should be in the format "<algorithm> <key>" e.g. "ssh-rsa AAAAB3NzaC1y..."
     *
     * @param string $publicKey
     * @param int $deviceId
     * @param string $assetKey
     */
    public function add(string $publicKey, int $deviceId, string $assetKey)
    {
        $publicKeyToAssetMap = $this->loadKeys();
        $publicKey = $this->validatePublicKeyFormat($publicKey);

        if (!array_key_exists($publicKey, $publicKeyToAssetMap)) {
            $publicKeyToAssetMap[$publicKey] = [];
            $publicKeyToAssetMap[$publicKey]['assets'] = [];
            /*
             * Note: deviceId only gets set when the public key is added. It cannot be updated.
             * This assumes the same public key cannot be used across multiple devices.
             */
            $publicKeyToAssetMap[$publicKey]['deviceId'] = $deviceId;
        }

        if (!in_array($assetKey, $publicKeyToAssetMap[$publicKey]['assets'])) {
            $publicKeyToAssetMap[$publicKey]['assets'][] = $assetKey;
        }

        $this->saveKeys($publicKeyToAssetMap);
    }

    /**
     * "Deprovision" an asset from being able to authenticate via SpeedSync
     *
     * @param string $assetKey
     */
    public function remove(string $assetKey)
    {
        $publicKeyToAssetMap = $this->loadKeys();

        foreach ($publicKeyToAssetMap as $publicKey => $map) {
            $assetMapKey = array_search($assetKey, $map['assets'] ?? [], true);

            if ($assetMapKey === false) {
                continue;
            }

            unset($publicKeyToAssetMap[$publicKey]['assets'][$assetMapKey]);
            $publicKeyToAssetMap[$publicKey]['assets'] = array_values($publicKeyToAssetMap[$publicKey]['assets']);

            if (empty($publicKeyToAssetMap[$publicKey]['assets'])) {
                unset($publicKeyToAssetMap[$publicKey]);
            }

            $this->saveKeys($publicKeyToAssetMap);
            return;
        }
    }

    /**
     * Generate an authorized_keys file based on the speedSyncAuthorizedKeys config file.
     *
     * @return string
     */
    public function generateAuthorizedKeys(): string
    {
        $output = '';
        $publicKeyToAssetMap = $this->loadKeys();
        foreach ($publicKeyToAssetMap as $publicKey => $assetInfo) {
            $deviceId = $assetInfo['deviceId'];
            $assetString = implode(',', $assetInfo['assets']);
            $output .= sprintf(self::AUTHORIZED_KEY_FORMAT, $deviceId, $assetString, $publicKey) . PHP_EOL;
        }
        return $output;
    }

    /**
     * Read the config file containing the mapping between public keys and assets.
     *
     * @return array
     */
    private function loadKeys(): array
    {
        $contents = $this->deviceConfig->getRaw(self::CONFIG_FILE_KEY, '{}');
        return json_decode($contents, true);
    }

    /**
     * Write the map to the config file.
     *
     * @param array $publicKeyToAssetsMap
     */
    private function saveKeys(array $publicKeyToAssetsMap)
    {
        $contents = json_encode($publicKeyToAssetsMap);
        $this->deviceConfig->setRaw(self::CONFIG_FILE_KEY, $contents);
    }

    /**
     * Assume public key is in format "<algorithm> <key> ..." and only return the algorithm and key
     *
     * @param string $publicKey
     *
     * @return string
     */
    private function validatePublicKeyFormat(string $publicKey): string
    {
        $allParts = explode(' ', trim($publicKey));
        if (count($allParts) < 2) {
            throw new Exception('Invalid public key format.');
        }

        $partsToKeep = array_slice($allParts, 0, 2);
        $publicKey = implode(' ', $partsToKeep);

        return $publicKey;
    }
}
