<?php

namespace Datto\Asset\Agent\Encryption;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetInfoSyncService;
use Datto\Cloud\JsonRpcClient;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Curl\CurlHelper;
use Datto\Log\LoggerAwareTrait;
use Datto\RemoteWeb\RemoteWebService;
use Datto\Resource\DateTimeService;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Responsible for uploading/downloading agent encryption keys from the Webserver
 * This allows users to do restores of encrypted agents in the cloud (when they supply their passphrase)
 * and for us to recover the keys if they were deleted.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class CloudEncryptionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AgentService $agentService;
    private AssetInfoSyncService $assetInfoSyncService;
    private AgentConfigFactory $agentConfigFactory;
    private DateTimeService $dateTimeService;
    private DeviceConfig $deviceConfig;
    private CurlHelper $curlHelper;
    private JsonRpcClient $deviceWeb;

    public function __construct(
        AgentService $agentService,
        AssetInfoSyncService $assetInfoSyncService,
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService,
        DeviceConfig $deviceConfig,
        CurlHelper $curlHelper,
        JsonRpcClient $deviceWeb
    ) {
        $this->agentService = $agentService;
        $this->assetInfoSyncService = $assetInfoSyncService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->dateTimeService = $dateTimeService;
        $this->deviceConfig = $deviceConfig;
        $this->curlHelper = $curlHelper;
        $this->deviceWeb = $deviceWeb;
    }

    /**
     * Upload the encryption key stashes for all agents to the cloud.
     * If pairing a new agent, pass it here to do any necessary one time cloud notifications.
     *
     * @param string|null $newAgent
     * @param bool $doSync True to perform the assetInfoSyncService->sync() call, false to skip it
     */
    public function uploadEncryptionKeys(string $newAgent = null, bool $doSync = true): void
    {
        try {
            if ($doSync) {
                $this->assetInfoSyncService->sync($newAgent);
            }
        } catch (Exception $e) {
            $this->logger->error('CES0001 Failed to sync asset info before uploading encryption keys', ['exception' => $e]);
            // We continue if this fails. It may still be possible to upload keys if the asset info was synced earlier.
        }

        try {
            $agentKeys = [];
            $allAgents = $this->agentService->getAllLocal(); // only the original agent can set encryption keys

            foreach ($allAgents as $agent) {
                $agentConfig = $this->getAgent($agent->getKeyName());
                $encryptionKeyStashRecord = new EncryptionKeyStashRecord();

                if ($agentConfig->isEncrypted()) {
                    if (!$agentConfig->loadRecord($encryptionKeyStashRecord)) {
                        throw new Exception('No key stash for ' . $agent->getKeyName());
                    }

                    // We store keys in the agentConfig as "mkey_" but the deviceWeb endpoint expects them as "masterkey_"
                    // So translate them here
                    $encryptionKeyStashArray = $encryptionKeyStashRecord->jsonSerialize();
                    $agentKeys[$agent->getKeyName()] = [
                        'masterkey_hash' => $encryptionKeyStashArray['mkey_hash'],
                        'masterkey_hash_alg' => $encryptionKeyStashArray['mkey_hash_alg'],
                        'user_keys' => $encryptionKeyStashArray['user_keys'],
                        EncryptionKeyStashRecord::USER_KEYS_JWE_KEY =>
                            $encryptionKeyStashArray[EncryptionKeyStashRecord::USER_KEYS_JWE_KEY]
                    ];
                }
            }

            // no need to upload empty array of keys since it is a no-op
            if (!empty($agentKeys)) {
                $this->deviceWeb->queryWithId('v1/device/asset/agent/saveMultipleKeys', [ 'keys' => $agentKeys ]);
            }
        } finally {
            if (!empty($newAgent)) {
                $this->logger->info('CES0002 Sending encryption record for new agent.', [
                    'agent' => $newAgent
                ]);
                $remoteHost = RemoteWebService::getRemoteHost();
                $encryptionData = [
                    'timestamp' => $this->dateTimeService->getTime(),
                    'deviceID' => $this->deviceConfig->get('deviceID'),
                    'ip' =>  empty($remoteHost) ? null : $remoteHost,
                    'agentName' => $newAgent,
                    'userInitials' => '-' //this value was used in the legacy code, not sure why but let's not break it
                ];
                $this->curlHelper->send('encryptionRecord', $encryptionData);
            }
        }
    }

    /**
     * @param string $keyName
     */
    private function getAgent(string $keyName): AgentConfig
    {
        return $this->agentConfigFactory->create($keyName);
    }
}
