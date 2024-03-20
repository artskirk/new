<?php

namespace Datto\App\Controller\Api\V1\Device\Settings;

use Datto\Core\Network\WindowsDomain;
use Datto\Ipmi\IpmiService;
use Datto\Ipmi\IpmiUser;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\SanitizedException;
use Datto\Security\CommonRegexPatterns;
use Datto\Service\Networking\LinkBackup;
use Datto\Service\Networking\LinkService;
use Datto\Service\Networking\NetworkLink;
use Datto\Service\Networking\NetworkService;
use Datto\Utility\Network\IpAddress;
use Datto\Utility\Screen;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Throwable;

/**
 * API endpoint to query and change device network settings.
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
 * @package Datto\App\Controller\Api\V1\Device\Settings
 */
class Network implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const NETWORK_CHECK_SCREEN_NAME = 'networkCheck';

    private NetworkService $networkService;
    private LinkService $linkService;
    private IpmiService $ipmiService;
    private Screen $screen;
    private WindowsDomain $windowsDomain;
    private LinkBackup $linkBackup;

    public function __construct(
        NetworkService $networkService,
        LinkService $linkService,
        IpmiService $ipmiService,
        Screen $screen,
        WindowsDomain $windowsDomain,
        LinkBackup $linkBackup
    ) {
        $this->networkService = $networkService;
        $this->linkService = $linkService;
        $this->ipmiService = $ipmiService;
        $this->screen = $screen;
        $this->windowsDomain = $windowsDomain;
        $this->linkBackup = $linkBackup;
    }

    /**
     * API to change the IP Settings of a network link.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "linkId" = {
     *     @Symfony\Component\Validator\Constraints\NotBlank(),
     *     @Symfony\Component\Validator\Constraints\Uuid()
     *   },
     *   "ipMode" = @Symfony\Component\Validator\Constraints\Choice(choices = {"dhcp", "disabled", "static", "link-local"}, message="Invalid IP Mode"),
     *   "address" = @Symfony\Component\Validator\Constraints\Ip(),
     *   "gateway" = @Symfony\Component\Validator\Constraints\Ip(),
     *   "netmask" = @Symfony\Component\Validator\Constraints\Ip(),
     *   "jumboFrames" = @Symfony\Component\Validator\Constraints\Choice(choices = {"enabled", "disabled"}, message="Invalid jumbo frame setting"),
     * })
     * @param string $linkId
     * @param string $ipMode,
     * @param string|null $address,
     * @param string|null $netmask,
     * @param string|null $gateway,
     * @param string|null $jumboFrames
     */
    public function configureLink(
        string $linkId,
        string $ipMode,
        ?string $address = null,
        ?string $netmask = null,
        ?string $gateway = null,
        ?string $jumboFrames = null
    ): void {
        if ($ipMode === 'static') {
            if ($address === null || $address === '') {
                throw new Exception("The IP address is required");
            }
            if ($netmask === null || $netmask === '') {
                throw new Exception("The netmask is required");
            }
            if (!$this->checkIp($address)) {
                throw new Exception("The IP address is already in use by a different device");
            }
        }
        if ($this->isReachable($linkId, $ipMode, $address ?? '', $netmask ?? '')) {
            $this->linkService->configureLink(
                $linkId,
                $ipMode,
                IpAddress::fromAddrAndMask($address ?? '', $netmask ?? ''),
                IpAddress::fromAddr($gateway ?? ''),
                $jumboFrames === 'enabled'
            );
        } else {
            throw new Exception("Cannot disable network device on which user is connected");
        }
    }

    /**
     * API call to change the device hostname
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "hostname" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[a-z_\-0-9]+$~i", message="Invalid hostnamne")
     * })
     * @param $hostname
     */
    public function setHostname($hostname): void
    {
        if (strlen($hostname) > 16 || strlen($hostname) === 0) {
            throw new Exception('Hostname must be between 1 and 16 characters in length.');
        }
        $this->networkService->setHostname($hostname);
    }

    /**
     * Function Prototype for checking if device can accept change on that device
     * TODO FFD: add SWITCH cases for unreachable IP ranges and all stuff which can mess networking.
     * @psalm-ignore-nullable-return
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress PossiblyNullReference
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress PossiblyUndefinedArrayOffset
     */
    private function isReachable(string $linkId, string $ipMode = '', string $address = '', string $netmask = ''): bool
    {
        $connection = $this->linkService->getLinkById($linkId);

        if ($ipMode === 'disabled' && (strval($connection->getIpAddress()) === $_SERVER['SERVER_ADDR'])) {
            return false;
        }
        return true;
    }


    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "dnsServer1" = @Symfony\Component\Validator\Constraints\Ip(),
     *   "dnsServer2" = @Symfony\Component\Validator\Constraints\Ip(),
     *   "dnsServer3" = @Symfony\Component\Validator\Constraints\Ip()
     * })
     * @param string $dnsServer1
     * @param string $dnsServer2
     * @param string $dnsServer3
     * @param string $searchDomain
     */
    public function setDnsInfo($dnsServer1, $dnsServer2, $dnsServer3, $searchDomain): void
    {
        // Verify each domain looks valid before updating our DNS information
        $searchDomains = [];
        foreach (preg_split('/[\s,]+/', $searchDomain) as $domain) {
            if ($searchDomain === '') {
                continue;
            }
            if (!preg_match(CommonRegexPatterns::DOMAIN_NAME_RFC_1035, $domain)) {
                throw new ConstraintDefinitionException('Invalid search domain');
            }
            $searchDomains[] = $domain;
        }

        $this->networkService->updateGlobalDns([$dnsServer1, $dnsServer2, $dnsServer3], $searchDomains);
    }

    /**
     * API call to retrieve network link information for this device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_READ")
     * @return NetworkLink[]
     */
    public function getLinks(): array
    {
        return $this->linkService->getLinks();
    }

    /**
     * API call to add vlan for the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "parentLinkId" = {
     *     @Symfony\Component\Validator\Constraints\NotBlank(),
     *     @Symfony\Component\Validator\Constraints\Uuid()
     * }})
    */
    public function addVlan(string $parentLinkId, int $vid): void
    {
        $this->linkService->addVlan($parentLinkId, $vid);
    }

    /**
     * API call to delete a vlan from the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "vlanLinkId" = {
     *     @Symfony\Component\Validator\Constraints\NotBlank(),
     *     @Symfony\Component\Validator\Constraints\Uuid()
     * }})
     */
    public function deleteVlan(string $vlanLinkId): void
    {
        $this->linkService->deleteVlan($vlanLinkId);
    }

    /**
     * API call to create Bond.
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "memberLinkIds" = {
     *     @Symfony\Component\Validator\Constraints\All(
     *          @Symfony\Component\Validator\Constraints\NotBlank(),
     *          @Symfony\Component\Validator\Constraints\Uuid()
     *     ),
     *   },
     *     "bondMode" = @Symfony\Component\Validator\Constraints\Choice(choices = {"balance-rr", "active-backup", "802.3ad"}),
     *     "primaryLinkId" = @Symfony\Component\Validator\Constraints\Uuid()
     * })
     */
    public function createBond(string $bondMode, array $memberLinkIds, ?string $primaryLinkId): void
    {
        $this->linkService->createBond($bondMode, $memberLinkIds, $primaryLinkId);
    }

    /**
     * API call to remove Bond and set the configuration back to bridge mode..
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     */
    public function removeBond(): void
    {
        $this->linkService->removeBond();
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_READ")
     * @return array
     */
    public function getConnectivityStatus(): array
    {
        return $this->networkService->getConnectivityStatus();
    }

    /**
     * Disable IPMI on the device
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_IPMI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_IPMI_WRITE")
     */
    public function ipmiDisable(): void
    {
        if (!$this->ipmiService->disable()) {
            throw new Exception("Cannot disable IPMI.");
        }
    }

    /**
     * Get the current IPMI settings on the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_IPMI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_IPMI_READ")
     * @return array containing current IPMI settings.
     */
    public function getCurrentIpmiSettings()
    {
        $currentSettings = $this->ipmiService->getCurrentSettings();

        $settingsArray = array(
            'isEnabled' => $currentSettings->isEnabled(),
            'isStatic' => $currentSettings->isStatic(),
            'ipAddress' => $currentSettings->getIpAddress(),
            'subnetMask' => $currentSettings->getSubnetMask(),
            'gateway' => $currentSettings->getGateway()
        );
        $adminUsers = array();
        foreach ($currentSettings->getAdminUsers() as $user) {
            $adminUsers[] = array(
                'name' => $user->getName(),
                'userId' => $user->getUserId()
            );
        }
        $settingsArray['adminUsers'] = $adminUsers;

        return $settingsArray;
    }

    /**
     * Set the IPMI password for the specified user.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_IPMI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_IPMI_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "userName" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\_\.]+$~"),
     *   "userId" = @Symfony\Component\Validator\Constraints\Choice(choices = {1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16}),
     *   "password" = @Symfony\Component\Validator\Constraints\Regex(pattern="/^[!-~]{1,16}$/")
     * })
     * @param string $userName the user name of the IPMI user
     * @param int $userId the userID of the IPMI user
     * @param string $password the new password to set
     */
    public function setIpmiPassword($userName, $userId, $password): void
    {
        try {
            $user = new IpmiUser($userName, $userId);
            if (!$this->ipmiService->setPassword($user, $password)) {
                throw new Exception("There was a problem setting the IPMI password.");
            }
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$userName, $userId, $password]);
        }
    }

    /**
     * Set the IPMI network settings
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_IPMI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_IPMI_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "isDhcp" = @Symfony\Component\Validator\Constraints\Type(type="bool"),
     *   "staticIP" = @Symfony\Component\Validator\Constraints\Ip(),
     *   "subnetMask" = @Symfony\Component\Validator\Constraints\Ip(),
     *   "gatewayIP" = @Symfony\Component\Validator\Constraints\Ip()
     * })
     * @param boolean $isDhcp is a DHCP or Static network configuration being requested
     * @param string $staticIP IP address
     * @param string $subnetMask the subnet mask
     * @param string $gatewayIP the network gateway
     */
    public function setIpmiNetworkSettings($isDhcp, $staticIP = null, $subnetMask = null, $gatewayIP = null): void
    {
        if (!$this->ipmiService->setNetworkSettings($isDhcp, $staticIP, $subnetMask, $gatewayIP)) {
            throw new Exception("Cannot apply network settings.");
        }
    }

    /**
     * Check if the given IP address is ok to use to configure as a static IP for the device
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "ipAddress" = @Symfony\Component\Validator\Constraints\Ip(),
     * })
     * @param string $ipAddress IP address to check.
     *
     * @return bool true if the IP address is ok to use false otherwise
     */
    public function checkIp(string $ipAddress)
    {
        return $this->networkService->isIpAvailable(IpAddress::fromAddr($ipAddress));
    }

    /**
     * Set the device workgroup
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "workgroup" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-]+$~")
     * })
     * @param string $workgroup
     */
    public function setWorkgroup(string $workgroup): void
    {
        $this->windowsDomain->setWorkgroup($workgroup);
    }

    /**
     * Join device to a windows domain
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "domain" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\.]+$~"),
     *   "username" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\_\.\@]+$~"),
     *   "password" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[!-\~]+$~"),
     *   "passwordServer" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\_\.\@]+$~")
     * })
     * @param string $domain The name of the Windows Domain to join
     * @param string $username The username to use when joining the domain
     * @param string $password The password to use when joining the domain (base64 encoded)
     * @param string|null $passwordServer The optional passwordServer for the domain
     */
    public function joinDomain(string $domain, string $username, string $password, ?string $passwordServer = null): void
    {
        try {
            $this->windowsDomain->join($domain, $username, $password, $passwordServer ?? '');
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @return bool
     */
    public function leaveDomain()
    {
        $this->windowsDomain->leave();
        return true;
    }

    /**
     * Run network configuration test in a background process.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     *
     * @return bool
     */
    public function check(): bool
    {
        if ($this->screen->isScreenRunning(self::NETWORK_CHECK_SCREEN_NAME)) {
            return true;
        }

        return $this->screen->runInBackground(
            [
                'snapctl',
                'network:connectivity:test'
            ],
            self::NETWORK_CHECK_SCREEN_NAME
        );
    }

    /**
     * Is the network check still in progress
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     *
     * @return bool
     */
    public function isCheckRunning(): bool
    {
        return $this->screen->isScreenRunning(self::NETWORK_CHECK_SCREEN_NAME);
    }

    /**
     * Commit any pending change by cancelling the NetworkManager automatic rollback.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     *
     * @return bool
     */
    public function commitChange(): bool
    {
        return $this->linkBackup->commit();
    }

    /**
     * Get the current network change state.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_READ")
     *
     * @return string Current state: "none", "pending", or "reverted"
     */
    public function getChangeState(): string
    {
        return $this->linkBackup->getState();
    }

    /**
     * Acknowledgement from the User/UI that an automatic rollback of changes occurred.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_NETWORK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     */
    public function acknowledgeRevertChange(): void
    {
        $this->linkBackup->acknowledgeRevert();
    }
}
