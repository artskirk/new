<?php

namespace Datto\Service\Registration;

use Datto\Device\Serial;
use Datto\Service\CloudManagedConfig\CloudManagedConfigService;
use Datto\Util\RetryAttemptsExhaustedException;
use Datto\Utility\Cloud\SpeedSync;
use Exception;
use Throwable;
use Datto\Device\SecretKey;
use Datto\Utility\ByteUnit;
use Datto\Cloud\JsonRpcClient;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Service\Networking\NetworkService;
use Datto\Feature\FeatureService;
use Psr\Log\LoggerAwareInterface;
use Datto\Common\Utility\Filesystem;
use Datto\Config\LocalConfig;
use Datto\Https\HttpsService;
use Datto\System\Storage\StorageService;
use Datto\Utility\Azure\InstanceMetadata;
use Datto\Billing\Service;
use Datto\Util\RetryHandler;

class ActivationService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const AZURE_TAG_AUTHORIZATION_CODE = 'authorizationCode';
    const AZURE_TAG_MAC = 'mac';

    const AUTH_CODE_KEY = 'auth';
    const LEGACY_GENESIS_IMAGED_AUTH_CODE_FILE = '/datto/config/conversionSource/system.code';
    const DEVICE_ACTIVATE_AZURE_ENDPOINT = 'v1/device/authorization/activateAzure';
    const DEVICE_ACTIVATE_VIRTUAL_ENDPOINT = 'v1/device/authorization/activateVirtual';
    const DEVICE_ACTIVATE_IMAGED_ENDPOINT = 'v1/device/authorization/activateImaged';
    const DEVICE_VALIDATE_VIRTUAL_ENDPOINT = 'v1/device/authorization/validateVirtual';
    const DEVICE_VALIDATE_IMAGED_ENDPOINT = 'v1/device/authorization/validateImaged';
    const FULL_THROTTLE_BANDWIDTH = 99999; // don't limit bandwidth
    const TYPE_DEVICE = 'device';
    const KEY_IS_VIRTUAL = 'isVirtual';
    const HTTPS_CHECK_MAX_RETRIES = 30;
    const HTTPS_CHECK_MINUTES_BETWEEN_RETRIES = 1;

    // aiming for infinite; this practical limit should surface edge loop cases
    const DEVICE_ACTIVATION_MAX_RETIRES = 1000;
    const DEVICE_ACTIVATION_RETRY_SECONDS = 60;

    private DeviceConfig $deviceConfig;
    private LocalConfig $localConfig;
    private JsonRpcClient $deviceWeb;
    private HttpsService $httpsService;
    private InstanceMetadata $instanceMetadata;
    private StorageService $storageService;
    private Filesystem $filesystem;
    private SecretKey $secretKey;
    private NetworkService $networkService;
    private FeatureService $featureService;
    private Serial $serial;
    private Service $billingService;
    private SpeedSync $speedsyncUtility;
    private RetryHandler $retryHandler;

    private CloudManagedConfigService $cloudManagedConfigService;

    public function __construct(
        DeviceConfig $deviceConfig,
        LocalConfig $localConfig,
        JsonRpcClient $deviceWeb,
        HttpsService $httpsService,
        InstanceMetadata $instanceMetadata,
        StorageService $storageService,
        Filesystem $filesystem,
        SecretKey $secretKey,
        NetworkService $networkService,
        FeatureService $featureService,
        Serial $serial,
        Service $billingService,
        Speedsync $speedsyncUtility,
        RetryHandler $retryHandler,
        CloudManagedConfigService $cloudManagedConfigService
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->localConfig = $localConfig;
        $this->deviceWeb = $deviceWeb;
        $this->httpsService = $httpsService;
        $this->instanceMetadata = $instanceMetadata;
        $this->storageService = $storageService;
        $this->filesystem = $filesystem;
        $this->secretKey = $secretKey;
        $this->networkService = $networkService;
        $this->featureService = $featureService;
        $this->serial = $serial;
        $this->billingService = $billingService;
        $this->speedsyncUtility = $speedsyncUtility;
        $this->retryHandler = $retryHandler;
        $this->cloudManagedConfigService = $cloudManagedConfigService;
    }

    /**
     * Auto activate the device if possible.
     */
    public function autoActivate()
    {
        try {
            $this->logger->info('REG1001 Attempting to auto activate device');

            if ($this->deviceConfig->isAzureDevice()) {
                if (intval($this->localConfig->get('reg')) === 1) {
                    $this->logger->info('REG1009 Device is already registered');
                    return;
                }
                $this->overrideAzureMac();

                $authorizationCode = $this->getAuthorizationCodeFromImds();

                // Try to create the pool before we check if the device is activated for idempotence
                $this->createAzurePool();

                $this->logger->info('REG1002 Auto activating azure device', [
                    'authorizationCode' => $authorizationCode
                ]);

                try {
                    $this->retryHandler->executeAllowRetry(
                        function () use ($authorizationCode) {
                            $this->activateAuthorizationCode($authorizationCode);
                        },
                        self::DEVICE_ACTIVATION_MAX_RETIRES,
                        self::DEVICE_ACTIVATION_RETRY_SECONDS
                    );
                } catch (RetryAttemptsExhaustedException $e) {
                    $this->logger->error(
                        "REG1010 Azure device activation failed after quasi-infinite retries timed out between the device and device web"
                    );

                    throw $e;
                }

                $this->logger->info('REG1005 Azure device has been activated', [
                    'authorizationCode' => $authorizationCode,
                    'deviceId' => $this->deviceConfig->getDeviceId()
                ]);

                // update service info so we can offsite without waiting for cron
                $this->billingService->getServiceInfo([]);

                // Use the less CPU intensive compression from ZFS
                $this->speedsyncUtility->setOption(SpeedSync::OPTION_COMPRESSION, SpeedSync::OPTION_COMPRESSION_ZFS);
                $this->localConfig->set('txSpeed', self::FULL_THROTTLE_BANDWIDTH);
            } else {
                throw new Exception('Auto activation is not supported for this device type');
            }
        } catch (Throwable $e) {
            $this->logger->error('REG1004 Could not auto activate device', ['exception' => $e]);

            throw $e;
        }
    }

    /**
     * Auto register the storage node for the device if possible.
     */
    public function autoRegisterStorageNode()
    {
        try {
            $this->logger->info('REG1011 Attempting to auto register storage node');
            if ($this->deviceConfig->isAzureDevice()) {
                $logContext = [
                    'deviceId' => $this->deviceConfig->getDeviceId()
                ];
                if (intval($this->localConfig->get('reg')) !== 1) {
                    $this->logger->info('REG1018 Device is not registered');
                    return;
                }
                if (count($this->speedsyncUtility->getTargetInfo(false)) > 0) {
                    $this->logger->info('REG1019 Device is already registered to a storage node');
                    return;
                }

                $this->logger->info('REG1012 Registering storage node to azure device', $logContext);

                $this->retryHandler->executeAllowRetry(
                    function () {
                        $this->deviceWeb->queryWithId('v1/device/datacenter/registerDeviceToStorageNode');
                    },
                    self::DEVICE_ACTIVATION_MAX_RETIRES,
                    self::DEVICE_ACTIVATION_RETRY_SECONDS
                );

                $this->logger->info('REG1015 Storage node registered to azure device', $logContext);
            } else {
                throw new Exception('Auto storage node registration is not supported for this device type');
            }
        } catch (Throwable $e) {
            $this->logger->error('REG1014 Could not auto register storage node for this device', [
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Check if an authorization code is valid, enabled, and has remaining uses.
     * Returns an array with the product 'model', 'capacity' in GB and 'serviceType', otherwise false.
     *
     * @param string $authorizationCode
     * @return mixed
     */
    public function validateAuthorizationCode($authorizationCode)
    {
        $isVirtual = $this->deviceConfig->has(self::KEY_IS_VIRTUAL);
        $method = $isVirtual ? self::DEVICE_VALIDATE_VIRTUAL_ENDPOINT : self::DEVICE_VALIDATE_IMAGED_ENDPOINT;
        return $this->deviceWeb->query($method, ['authorizationCode' => $authorizationCode]);
    }

    /**
     * Get the authorization code stored on the device. This is not present for physical or unauthorized devices.
     *
     * @return string Auth code or empty string if there is no auth code
     */
    public function getStoredAuthorizationCode()
    {
        $code = $this->deviceConfig->get(static::AUTH_CODE_KEY);
        if ($code === false) {
            $code = @$this->filesystem->fileGetContents(static::LEGACY_GENESIS_IMAGED_AUTH_CODE_FILE);
        }
        if ($code === false) {
            $code = ""; // It's a physical device or it hasn't been authorized yet
        }
        return $code;
    }

    /**
     * Determines whether the device is authorized.
     *
     * @return bool True if authorized, false if not
     */
    public function isActivated()
    {
        if ($this->deviceConfig->isAzureDevice()) {
            return !empty($this->getStoredAuthorizationCode());
        }

        $serial = $this->serial->get();
        try {
            $deviceId = $this->deviceWeb->query('v1/device/exists', ['mac' => $serial]);
            return $deviceId !== 0;
        } catch (Exception $e) {
            $this->logger->error('REG1007 isAuthorized failed.', ['serial' => $serial, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Finalize an authorization in the cloud db.
     *
     * @param string $authorizationCode
     */
    public function activateAuthorizationCode($authorizationCode)
    {
        if ($this->isActivated()) {
            throw new Exception('Device is already authorized.');
        }
        $this->deviceConfig->clear('hardwareProductID');

        $serial = $this->serial->get();
        $secretKey = $this->secretKey->get();

        $data = [
            'mac' => $serial,
            'secretKey' => $secretKey,
            'authorizationCode' => $authorizationCode
        ];

        $isAzure = $this->deviceConfig->isAzureDevice();
        $isVirtual = $this->deviceConfig->has(self::KEY_IS_VIRTUAL);

        if ($isAzure) {
            $method = self::DEVICE_ACTIVATE_AZURE_ENDPOINT;
        } elseif ($isVirtual) {
            $method = self::DEVICE_ACTIVATE_VIRTUAL_ENDPOINT;
        } else {
            $method = self::DEVICE_ACTIVATE_IMAGED_ENDPOINT;
        }

        $result = $this->deviceWeb->query($method, $data);

        $this->processActivationResult($result);
        $this->deviceConfig->set('auth', trim($authorizationCode));
        if ($isAzure) {
            // For Azure devices, once the activation result has been processed, the device has been registered
            // also, run the https check to make sure it is set up with DDNS
            $this->localConfig->set('reg', 1);
            $this->runHttpsCheck();
        }

        // The device should have the quota set (imaged devices) and is not
        // expected to already have the quota set (virtual devices)
        if ($this->featureService->isSupported(FeatureService::FEATURE_SET_POOL_QUOTA) && !$isVirtual) {
            $capacityInGb = $this->deviceConfig->get('capacity', 0);

            if ($capacityInGb <= 0) {
                $this->logger->error("REG0008 Expected non-zero ZFS storage quota for imaged device.");
            } else {
                $this->logger->info("REG0009 Setting ZFS quota for imaged device.");
                $capacityInBytes = ByteUnit::GIB()->toByte($capacityInGb);
                $this->storageService->setDatasetQuota($capacityInBytes);
            }
        }

        if ($this->featureService->isSupported(FeatureService::FEATURE_CLOUD_MANAGED_CONFIGS)) {
            $this->logger->info('REG0052 Syncing local config to cloud');
            $this->cloudManagedConfigService->pushToCloud();
        } else {
            $this->logger->info('REG0053 Cloud managed config feature not supported, skipping.');
        }
    }

    private function createAzurePool()
    {
        if ($this->storageService->poolExists()) {
            $this->logger->info("REG0010 Pool already exists, skipping pool creation");

            return;
        }

        $poolMemberNames = $this->storageService->getAzureDataDiskNames();

        if (empty($poolMemberNames)) {
            $this->logger->error('REG0012 Could not detect pool member disks');

            throw new Exception('Could not detect pool member disks');
        }

        $this->storageService->createNewPool($poolMemberNames);
    }

    private function overrideAzureMac()
    {
        $serial = $this->getSerialFromImds();

        $this->logger->info('REG0011 Overriding serial with serial found in IMDS', [
            'serial' => $serial
        ]);

        $this->serial->override($serial);
    }

    /**
     * Performs further processing after activation success.
     *
     * @param array|string $activationResult
     */
    private function processActivationResult($activationResult)
    {
        $this->handleModelSpecificActivation($activationResult);
        $this->deviceConfig->set('hardwareProductID', $activationResult['productID']);
        $this->deviceConfig->set('model', $activationResult['product']);
        $this->deviceConfig->set('capacity', $activationResult['capacity']);
        $this->deviceConfig->set('deviceID', $activationResult['deviceID']);
        if (!empty($activationResult['hostname'])) {
            $this->networkService->setHostname($activationResult['hostname']);
        }
        $this->deviceWeb->rebuildClient();
    }

    /**
     * Handle the specific steps required after validating a device key
     *
     * @param array|string $activationResult
     */
    private function handleModelSpecificActivation($activationResult)
    {
        $isDeviceType = preg_match('/^(S3?[VI]|A3?V|SRV)\d+$/', $activationResult['product']);

        if ($isDeviceType) {
            $this->deviceConfig->set('type', self::TYPE_DEVICE);
        } else {
            throw new Exception(
                'Could not determine device type. Please contact Support.'
            );
        }
        if (preg_match('/^A3?V\d+$/', $activationResult['product'])) {
            $this->deviceConfig->set('isAltoXL', true);
        }
    }

    private function getAuthorizationCodeFromImds(): string
    {
        try {
            return $this->getTagFromImds(self::AZURE_TAG_AUTHORIZATION_CODE);
        } catch (Throwable $e) {
            $this->logger->error('REG1003 Could not read activation UUID from IMDS', ['exception' => $e]);

            throw $e;
        }
    }

    private function getSerialFromImds(): string
    {
        try {
            return $this->getTagFromImds(self::AZURE_TAG_MAC);
        } catch (Throwable $e) {
            $this->logger->error('REG1006 Could not read mac from IMDS', ['exception' => $e]);

            throw $e;
        }
    }

    /**
     * @param string $tag
     * @return mixed
     */
    private function getTagFromImds(string $tag)
    {
        if (!$this->instanceMetadata->isSupported()) {
            throw new Exception('IMDS is not supported on this device');
        }

        $tags = $this->instanceMetadata->getTags();
        if (!isset($tags[$tag])) {
            throw new Exception('Expected to find an "' . $tag . '" get in IMDS tags');
        }

        return $tags[$tag];
    }

    private function runHttpsCheck()
    {
        try {
            $this->retryHandler->executeAllowRetry(
                function () {
                    $isSuccessful = $this->httpsService->check(false);
                    if (!$isSuccessful) {
                        throw new Exception('Attempt to check and renew https certificate was unsuccessful');
                    }
                },
                self::HTTPS_CHECK_MAX_RETRIES,
                self::HTTPS_CHECK_MINUTES_BETWEEN_RETRIES * 60
            );
        } catch (Throwable $e) {
            $this->logger->error('REG1008 Could not check and renew https certificates', ['exception' => $e]);
            throw $e;
        }
    }
}
