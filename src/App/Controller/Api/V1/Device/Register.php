<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Log\SanitizedException;
use Datto\Service\Registration\ActivationService;
use Datto\System\Storage\StorageDevice;
use Datto\System\Storage\StorageService;
use Datto\Service\Registration\RegistrationService;
use Datto\Service\Registration\Registrant;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Datto\Networking\NetworkConfiguration\NetworkConfigurationService;
use Throwable;

/**
 * This class contains the API endpoints for registering a device.
 * THIS ENDPOINT IS NOT SECURED. DO NOT REVEAL SENSITIVE DATA, IT CAN BE ACCESSED BY ANYONE.
 * Endpoints should only work when the device is unregistered.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Register
{
    /** @var ActivationService */
    private $activationService;

    /** @var RegistrationService */
    private $registrationService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var NetworkConfigurationService */
    private $networkConfigurationService;

    /** @var StorageService */
    private $storageService;

    public function __construct(
        ActivationService $activationService,
        RegistrationService $registrationService,
        DeviceLoggerInterface $logger,
        NetworkConfigurationService $networkConfigurationService,
        StorageService $storageService
    ) {
        $this->activationService = $activationService;
        $this->registrationService = $registrationService;
        $this->logger = $logger;
        $this->networkConfigurationService = $networkConfigurationService;
        $this->storageService = $storageService;

        AnnotationRegistry::registerLoader('class_exists');//required for doctrine autoloader to find custom annotations
    }

    /**
     * Gets the current progress through updates.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     * @return array
     */
    public function getProgress()
    {
        $this->blockWhenRegistered();

        return array(
            'isUpgradeRunning' => $this->registrationService->isUpgradeRunning(),
            'wasUpgradeSuccessful' => $this->registrationService->wasUpgradeSuccessful(),
        );
    }

    /**
     * Gets all the client organizations for the partner
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     * @return array of company name strings with client id int keys
     */
    public function getClients()
    {
        $this->blockWhenRegistered();

        return $this->registrationService->getClients();
    }

    /**
     * Attempts an upgrade via upgradectl.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     */
    public function attemptUpgrade(): void
    {
        $this->blockWhenRegistered();

        $this->registrationService->attemptImageUpgrade();
    }

    /**
     * Register a device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "user" = {
     *          @Symfony\Component\Validator\Constraints\NotBlank(),
     *          @Symfony\Component\Validator\Constraints\Regex(pattern="/^[aA]dmin(istrator)?$/", match=false),
     *          @Symfony\Component\Validator\Constraints\Regex(pattern="/^[a-z][a-z0-9_-]*$/i", match=true)
     *     },
     *     "password" = @Symfony\Component\Validator\Constraints\NotBlank(),
     *     "hostname" = {
     *          @Symfony\Component\Validator\Constraints\NotBlank(),
     *          @Symfony\Component\Validator\Constraints\Regex(
     *              pattern="/^(?![0-9]+$)(?![\-]+)[a-zA-Z0-9\-]{0,15}[a-zA-Z0-9]$/"
     *          )
     *     },
     *     "timezone" = {
     *          @Symfony\Component\Validator\Constraints\NotBlank(),
     *          @Datto\App\Security\Constraints\TimeZone()
     *     },
     *     "email" = {
     *         @Symfony\Component\Validator\Constraints\Email()
     *     },
     *     "clientOrganizationId" = {
     *          @Symfony\Component\Validator\Constraints\Range(min=0, max=2147483647)
     *     },
     *     "clientOrganization" = {
     *          @Symfony\Component\Validator\Constraints\Length(min=0, max=255)
     *     },
     *     "datacenterLocation" = {
     *          @Symfony\Component\Validator\Constraints\Length(min=0, max=32)
     *     },
     *     "recommendedDatacenter" = {
     *          @Symfony\Component\Validator\Constraints\Length(min=0, max=32)
     *     },
     * })
     * @param string $user
     * @param string $password
     * @param string $hostname
     * @param string $timezone
     * @param string $email
     * @param int $clientOrganizationId
     * @param string $clientOrganization
     * @param string $datacenterLocation
     * @param string $recommendedDatacenter
     */
    public function send(
        string $user,
        string $password,
        string $hostname,
        string $timezone,
        string $email,
        string $datacenterLocation = '',
        string $recommendedDatacenter = ''
    ) {
        try {
            $this->blockWhenRegistered();
            $registrant = new Registrant(
                $user,
                $password,
                $hostname,
                $timezone,
                $email,
                filter_var($datacenterLocation, FILTER_SANITIZE_STRING),
                filter_var($recommendedDatacenter, FILTER_SANITIZE_STRING)
            );
            $this->registrationService->register($registrant);
            return true;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$user, $password]);
        }
    }

    /**
     * Checks if a authorizationCode is valid. Returns an array containing the 'model', 'capacity', and 'serviceType'
     * associated with the authorizationCode, otherwise false.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "authorizationCode" = {
     *        @Symfony\Component\Validator\Constraints\Regex(pattern="/^[a-zA-Z0-9]{1,64}$/")
     *     }
     * })
     * @param string $authorizationCode
     * @return mixed
     */
    public function validateAuthorizationCode($authorizationCode)
    {
        $this->blockWhenRegistered();
        return $this->activationService->validateAuthorizationCode($authorizationCode);
    }

    /**
     * Updates a given authorization authorizationCode as used
     *
     * @Datto\App\Security\RequiresFeature ("FEATURE_REGISTRATION")
     *
     * @Datto\App\Security\RequiresPermission ("PERMISSION_REGISTRATION")
     *
     * @Datto\JsonRpc\Validator\Validate (fields={
     *     "authorizationCode" = {
     *
     * @Symfony\Component\Validator\Constraints\Regex (pattern="/^[a-zA-Z0-9]{1,64}$/")
     *     }
     * })
     *
     * @param string $authorizationCode
     *
     * @return void
     */
    public function activateAuthorizationCode($authorizationCode): void
    {
        $this->blockWhenRegistered();
        $authorizationCode = str_replace('-', '', $authorizationCode);
        $this->activationService->activateAuthorizationCode($authorizationCode);
    }

    /**
     * Return true if eth0 is connected
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     * @return bool
     */
    public function testNetworkConnection()
    {
        $this->blockWhenRegistered();
        return $this->networkConfigurationService->testNetworkConnection();
    }

    /**
     * Get information about the device's service contract (intended for imaged and virtual devices)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     * @return array information about the device's service contract
     *  (partner name (string), local capacity (string, with GB or TB), contract end date (int),
     *  cloud service type (nested array of timeBasedRetentionYears, isInfiniteRetention, isLocalOnly, isPrivateServer
     */
    public function getServiceInfo()
    {
        $this->blockWhenRegistered();
        return $this->registrationService->getServiceInfo();
    }

    /**
     * Get the default datacenter for the device's linked reseller.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     * @return string
     */
    public function getDefaultLocation(): string
    {
        $this->blockWhenRegistered();
        return $this->registrationService->getDefaultDatacenterLocation();
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     * @throws Exception
     */
    public function checkStorage(): array
    {
        $this->storageService->rescanDevices();
        $devices = $this->storageService->getDevices();
        foreach ($devices as $device) {
            if ($device->getStatus() === StorageDevice::STATUS_POOL) {
                return [
                    'poolExists' => $this->storageService->poolExists(),
                    'capacity' => $device->getCapacityInGb(),
                    'name' => $device->getName(),
                    'empty' => $this->storageService->poolEmpty()
                ];
            } elseif ($device->getStatus() === StorageDevice::STATUS_AVAILABLE) {
                return [
                    'capacity' => $device->getCapacityInGb(),
                    'name' => $device->getName(),
                ];
            }
        }
        throw new \Exception('No storage drives attached');
    }

    /**
     * Sets up storage on the specified drive
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REGISTRATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REGISTRATION")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "drive" = {
     *        @Symfony\Component\Validator\Constraints\NotBlank(),
     *        @Symfony\Component\Validator\Constraints\Regex("~^/dev/[a-zA-Z0-9]+$~")
     *     }
     * })
     * @param string $drive
     */
    public function createStorage($drive): void
    {
        $this->blockWhenRegistered();

        try {
            $this->storageService->createNewPool(array($drive));
        } catch (\Exception $e) {
            // rethrow exception for security because original message reveals too much information
            throw new Exception('Failed to create storage', $e->getCode());
        }
    }

    /**
     * Throws an exception if the device is registered. Use this on every endpoint here to lock down their use.
     */
    private function blockWhenRegistered(): void
    {
        if ($this->registrationService->isRegistered()) {
            $this->logger->error("REG0006 Call to unsecured endpoint when already registered. This should not happen.");
            throw new \LogicException("Not available when already registered");
        }
    }
}
