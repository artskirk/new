<?php

namespace Datto\Service\Registration;

use Datto\Asset\AssetService;
use Datto\Backup\SecondaryReplicationService;
use Datto\Billing\Service;
use Datto\Cloud\JsonRpcClient;
use Datto\Cloud\CloudErrorException;
use Datto\Cloud\SpeedTestService;
use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Config\LocalConfig;
use Datto\Config\Login\LocalLoginService;
use Datto\Feature\FeatureService;
use Datto\Https\HttpsService;
use Datto\Log\LoggerAwareTrait;
use Datto\Service\CloudManagedConfig\CloudManagedConfigService;
use Datto\Service\Device\EnvironmentService;
use Datto\Service\Networking\NetworkService;
use Datto\Utility\Systemd\Systemctl;
use Datto\Upgrade\UpgradeService;
use Datto\User\UserService;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\Util\Email\EmailService;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * RegistrationService handles device registration.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class RegistrationService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const KEY_REGISTRATION_REBOOT_DONE = "registrationRebootDone";
    const TWO_DAYS_IN_SEC = 172800;
    const DEFAULT_DATACENTER_LOCATION = "United States";
    const REGISTRATION_COMPLETED_RECENTLY_KEY = 'registrationCompletedRecently';

    private DeviceConfig $deviceConfig;
    private LocalConfig $localConfig;
    private UserService $userService;
    private NetworkService $networkService;
    private DateTimeZoneService $dateTimeZoneService;
    private JsonRpcClient $client;
    private DateTimeService $dateTimeService;
    private Service $billingService;
    private SpeedTestService $speedTestService;
    private SshKeyService $sshKeyService;
    private UpgradeService $upgradeService;
    private AssetService $assetService;
    private EmailService $emailService;
    private HttpsService $httpsService;
    private SecondaryReplicationService $secondaryReplicationService;
    private LocalLoginService $localLoginService;
    private DeviceState $deviceState;
    private Systemctl $systemctl;
    private EnvironmentService $environmentService;
    private CloudManagedConfigService $cloudManagedConfigService;
    private FeatureService $featureService;

    public function __construct(
        DeviceConfig $deviceConfig,
        LocalConfig $localConfig,
        UserService $userService,
        DateTimeZoneService $dateTimeZoneService,
        NetworkService $networkService,
        JsonRpcClient $client,
        DateTimeService $dateTimeService,
        Service $billingService,
        UpgradeService $upgradeService,
        SpeedTestService $speedTestService,
        SshKeyService $sshKeyService,
        AssetService $assetService,
        EmailService $emailService,
        HttpsService $httpsService,
        SecondaryReplicationService $secondaryReplicationService,
        LocalLoginService $localLoginService,
        DeviceState $deviceState,
        Systemctl $systemctl,
        EnvironmentService $environmentService,
        CloudManagedConfigService $cloudManagedConfigService,
        FeatureService $featureService
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->localConfig = $localConfig;
        $this->userService = $userService;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->networkService = $networkService;
        $this->client = $client;
        $this->dateTimeService = $dateTimeService;
        $this->billingService = $billingService;
        $this->upgradeService = $upgradeService;
        $this->speedTestService = $speedTestService;
        $this->sshKeyService = $sshKeyService;
        $this->assetService = $assetService;
        $this->emailService = $emailService;
        $this->httpsService = $httpsService;
        $this->secondaryReplicationService = $secondaryReplicationService;
        $this->localLoginService = $localLoginService;
        $this->deviceState = $deviceState;
        $this->systemctl = $systemctl;
        $this->environmentService = $environmentService;
        $this->cloudManagedConfigService = $cloudManagedConfigService;
        $this->featureService = $featureService;
    }

    /**
     * @return bool Whether the device is registered
     */
    public function isRegistered()
    {
        return intval($this->localConfig->get('reg')) === 1;
    }

    /**
     * Attempts to update the base image.
     *
     */
    public function attemptImageUpgrade()
    {
        $this->upgradeService->setChannelToDefaultIfNotSet();
        $this->upgradeService->upgradeToLatestUpgradectl();
        $this->upgradeService->upgradeToLatestImage();
    }

    /**
     * Checks whether upgradectl is currently running.
     *
     * @return bool
     */
    public function isUpgradeRunning()
    {
        return $this->upgradeService->isUpgradeRunning();
    }

    /**
     * Checks if the image upgrade was successful.
     *
     * @return bool
     */
    public function wasUpgradeSuccessful()
    {
        return $this->upgradeService->upgradeSuccessful();
    }

    /**
     * Get the default recommended datacenter country based on the linked reseller.
     *
     * @return string
     */
    public function getDefaultDatacenterLocation(): string
    {
        try {
            $datacenterLocation = $this->client->queryWithId('v1/device/registration/getDefaultLocation');
        } catch (Throwable $e) {
            $datacenterLocation = null;
        }

        return empty($datacenterLocation) ? self::DEFAULT_DATACENTER_LOCATION : $datacenterLocation;
    }

    /**
     * Return whether or not the datacenter selection should be shown
     *
     * @return bool
     */
    public function shouldDisplayDatacenterLocations(): bool
    {
        try {
            $serviceInfo = $this->getServiceInfo();
        } catch (Throwable $e) {
            $this->logger->error('REG0100 Error getting service info for device', ['exception' => $e]);
            return false;
        }
        $localOnly = $serviceInfo['cloudService']['isLocalOnly'] ?? false;
        $privateNode = $serviceInfo['cloudService']['isPrivateServer'] ?? false;
        return !$localOnly && !$privateNode;
    }

    /**
     * Register a device
     *
     * @param Registrant $registrant Holds the registration information
     */
    public function register(Registrant $registrant)
    {
        $this->deviceConfig->clear(self::KEY_REGISTRATION_REBOOT_DONE);

        $canOffsite = false;

        try {
            $this->logger->info('REG0000 Registering device');

            $datacenter = $registrant->getDatacenterLocation();

            $response = $this->client->queryWithId('v1/device/registration/registerNow', [
                // clientId and clientName are no longer used, but this query requires that the empty fields remain
                'clientID' => null,
                'clientName' => null,
                'datacenterLocation' => $datacenter
            ]);

            $nonDefaultDatacenterSelected = $datacenter && $datacenter !== $registrant->getRecommendedDatacenter();
            if ($nonDefaultDatacenterSelected) {
                $this->logger->info("REG1000 Admin user selected non-default datacenter", ['selectedDatacenter' => $datacenter, 'recommendedDatacenter' => $registrant->getRecommendedDatacenter()]);
            }

            $this->networkService->setHostname($registrant->getHostname());
            $this->userService->create($registrant->getUser(), $registrant->getPassword());
            $this->dateTimeZoneService->setTimeZone($registrant->getTimezone());
            $this->emailService->setDeviceAlertsEmail($registrant->getEmail());

            if (isset($response['canOffsite']) && $response['canOffsite'] === true) {
                $this->logger->info('REG0009 This is an offsite device, generating SSH key...');
                $this->sshKeyService->generateSshKeyIfNotExists();
                $this->logger->info('REG0010 Sending SSH key...');
                $this->sshKeyService->sendKeyToWebserver();
                $canOffsite = true;
            } else {
                $this->logger->info('REG0011 This is a NO offsite device.');
            }
            // Commit the registered state to the device settings
            $this->localConfig->set('reg', 1);
            $assets = $this->assetService->getAll();
            $this->billingService->getServiceInfo($assets); // update service info so we can offsite without waiting for cron
        } catch (CloudErrorException $e) {
            $this->localConfig->set('reg.error', var_export($e->getErrorObject(), true));
            $this->logger->error('REG0108 Registration failed on server.', ['exception' => $e]);
            throw $e;
        } catch (Throwable $e) {
            $this->localConfig->set('reg.error', $e->getMessage());
            $this->logger->error('REG0002 Registration failed', ['exception' => $e]);
            throw $e;
        }

        $this->environmentService->writeEnvironment();

        if ($canOffsite) {
            $this->configureOffsiteSpeed();
            $this->configureSecondaryReplication();
        } else {
            $this->logger->info('REG0013 Speed Test not necessary for no offsite device.');
        }

        try {
            $this->localConfig->migrate();
        } catch (Throwable $e) {
            $this->logger->error('REG0018 Error migrating the "/home/_config" directory to a symbolic link', ['exception' => $e]);
        }

        try {
            // We need to run the https check to setup the ddns domain to allow p2p devices to select this device as a
            //   replication target during pairing.
            // However, if we run the check during registration, we'll redirect to the https url and the automatic login
            //   at the end of registration won't work since the session cookie was attached to the http url. We can't
            //   do the autologin to the https url since it's a cross site request and gets blocked.
            // To solve this, we run the check without enabling the redirect. It will be enabled the next time the
            //   check runs (every ~2 hours).
            $this->httpsService->checkWithoutRedirect();
        } catch (Throwable $e) {
            $this->logger->error('REG0015 Failed to perform https:check', ['exception' => $e]);
        }

        // Disable local login by default for security
        $this->localLoginService->disable();

        if ($this->featureService->isSupported(FeatureService::FEATURE_CLOUD_MANAGED_CONFIGS)) {
            $this->logger->info('REG0050 Syncing local config to cloud');
            $this->cloudManagedConfigService->pushToCloud();
        } else {
            $this->logger->info('REG0051 Cloud managed config feature not supported, skipping.');
        }

        // Display the Registration Completed message at the next login window
        $this->deviceState->touch(self::REGISTRATION_COMPLETED_RECENTLY_KEY);

        $this->logger->info('REG0001 Registration successful');

        // Reload php-fpm so the new timezone will take effect after the current request completes.
        // This must be done at the end to avoid killing the current request.
        $this->systemctl->reload('php7.4-fpm.service');
    }

    /**
     * Gets all the client organizations for the partner
     *
     * @return array of company name strings with client id int keys
     */
    public function getClients()
    {
        try {
            return $this->client->queryWithId('v1/device/registration/listClients');
        } catch (Throwable $e) {
            $this->logger->error('REG0007 Failed to retrieve client list', ['exception' => $e]);
            throw $e;
        }
    }

    /**
     * Get information about the device's service contract (intended for imaged and virtual devices)
     *
     * @return array information about the device's service contract
     *  (partner name (string), local capacity (string, with GB or TB), contract end date (int),
     *  cloud service type (nested array of timeBasedRetentionYears, isInfiniteRetention, isLocalOnly, isPrivateServer))
     */
    public function getServiceInfo()
    {
        $serviceInfo = $this->client->queryWithId('v1/device/registration/getServiceInfo');
        return $serviceInfo;
    }

    /**
     * @return bool True if device was imaged in the past two days, otherwise false
     */
    public function wasJustImaged()
    {
        $imagedTime = intval($this->deviceConfig->get('imagingDate'));
        return $imagedTime > $this->dateTimeService->getTime() - self::TWO_DAYS_IN_SEC;
    }

    private function configureOffsiteSpeed()
    {
        // Do a speed test but don't fail registration if it doesn't work
        try {
            $this->speedTestService->runSpeedTest(12500);
        } catch (Throwable $e) {
            $this->logger->warning('REG0017 Speed test failed to set an offsite speed', ['exception' => $e]);
        }
    }

    private function configureSecondaryReplication()
    {
        try {
            if ($this->secondaryReplicationService->isAvailable()) {
                $this->secondaryReplicationService->enable();
            }
        } catch (Throwable $e) {
            $this->logger->warning('REG0016 Failure setting up secondary replication', ['exception' => $e]);
        }
    }
}
