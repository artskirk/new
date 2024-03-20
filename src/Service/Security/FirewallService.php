<?php

namespace Datto\Service\Security;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\Security\AllowForwardForLinks;
use Datto\Service\Networking\LinkService;
use Datto\Utility\Firewall\FirewallCmd;
use Datto\Utility\Firewall\FirewalldUserOverrideManager;
use Datto\Utility\Network\NetworkManager\NetworkManager;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Configures firewall rules for the device.
 * The updated CentOS7/8 tutorials recommend firewalld
 * https://www.digitalocean.com/community/tutorials/how-to-set-up-a-firewall-using-firewalld-on-centos-8
 *
 * @author Philipp Heckel <ph@datto.com>
 * @author Alex Joseph <ajoseph@datto.com>
 */
class FirewallService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const APPLIED_FILE = '/dev/shm/firewall-applied';
    const APACHE_CONNECTOR_PORT = 8443;
    const HTTPS_PORT = 443;
    public const DATTO_ZONE ='datto';
    public const LIBVIRT_ZONE = 'libvirt';
    public const TRUSTED_ZONE = 'trusted';
    public const DATTO_ZONE_FILE = '/etc/firewalld/zones/datto.xml';
    public const DIRECT_RULES_FILE = '/etc/firewalld/direct.xml';

    private ProcessFactory $processFactory;

    private DeviceConfig $deviceConfig;

    private Filesystem $filesystem;

    private FeatureService $featureService;

    private FirewallCmd $firewallCmd;

    private LinkService $linkService;

    private AllowForwardForLinks $allowForwardForLinks;

    private NetworkManager $networkManager;

    private FirewalldUserOverrideManager $firewalldUserOverrideManager;

    public function __construct(
        ProcessFactory $processFactory,
        DeviceConfig $deviceConfig,
        Filesystem $filesystem,
        FeatureService $featureService,
        FirewallCmd $firewallCmd,
        LinkService $linkService,
        AllowForwardForLinks $allowForwardForLinks,
        NetworkManager $networkManager,
        FirewalldUserOverrideManager $firewalldUserOverrideManager
    ) {
        $this->processFactory = $processFactory;
        $this->deviceConfig = $deviceConfig;
        $this->filesystem = $filesystem;
        $this->featureService = $featureService;
        $this->firewallCmd = $firewallCmd;
        $this->linkService = $linkService;
        $this->allowForwardForLinks = $allowForwardForLinks;
        $this->networkManager = $networkManager;
        $this->firewalldUserOverrideManager = $firewalldUserOverrideManager;
    }

    /**
     * Applies default firewall rules for this device.
     *
     * The set of rules depends on what type of device it is, i.e.
     * server devices have stricter rules.
     * @param bool $force Force apply even if it has been applied already.
     */
    public function apply(bool $force = false): void
    {
        $requiredFirewalldZone = $this->requiredZoneForDevice();
        // If current firewalld zone is not the required zone, apply firewall again.
        if (($requiredFirewalldZone === $this->firewallCmd->getDefaultZone()) &&
            !$force &&
            $this->filesystem->exists(self::APPLIED_FILE)) {
            throw new Exception('Firewall rules already applied for zone: ' . $this->firewallCmd->getDefaultZone());
        }

        if ($this->filesystem->exists(self::APPLIED_FILE)) {
            $this->filesystem->unlink(self::APPLIED_FILE);
        }

        $this->resetFirewalldRules();
        $this->firewallCmd->setDefaultZone($requiredFirewalldZone);

        $networkConnections = $this->networkManager->getAllConnections();
        /* NetworkConnection $con */
        foreach ($networkConnections as $con) {
            // NetworkManager and Firewalld keeps track of zones for interfaces.
            // Change zone for interface in NetworkManager.
            $con->setFirewallZone($requiredFirewalldZone);
            // Change zone for interface in Firewalld.
            $this->firewallCmd->changeFirewallZoneForInterface($con->getName(), $requiredFirewalldZone);
        }

        $this->applyDefaultRules();

        if ($this->featureService->isSupported(FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS)) {
            $this->applyDirectToCloudRules();
        }

        $limitTcpConnectionPorts = [self::HTTPS_PORT];

        if ($this->deviceConfig->isCloudDevice()) {
            $this->applyServerRules();
            $limitTcpConnectionPorts []= self::APACHE_CONNECTOR_PORT;
        } elseif ($this->deviceConfig->isAzureDevice()) {
            $this->applyServerRules();
            $this->applyAzureDeviceRules();
            $limitTcpConnectionPorts []= self::APACHE_CONNECTOR_PORT;
        } else {
            $this->applyOnPremRules();
        }

        if ($this->featureService->isSupported(FeatureService::FEATURE_TCP_CONNECTION_LIMITING)) {
            foreach ($limitTcpConnectionPorts as $limitPort) {
                $this->firewallCmd->setRateLimiting($limitPort);
            }
        }

        if ($this->firewallCmd->getDefaultZone() === self::DATTO_ZONE) {
            $this->applyUserConfiguredRules(self::DATTO_ZONE);
        }

        $this->filesystem->touch(self::APPLIED_FILE);
    }

    /**
     * Determine the firewalld zone the device should be in.
     */
    public function requiredZoneForDevice(): string
    {
        $requiredZone = self::DATTO_ZONE;

        if (!$this->featureService->isSupported(FeatureService::FEATURE_RESTRICTIVE_FIREWALL) &&
            !$this->featureService->isSupported(FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS) &&
            !$this->deviceConfig->isCloudDevice()
        ) {
            //Cloud devices always has restricted firewall zone - datto.
            $requiredZone = self::TRUSTED_ZONE;
        }
        return $requiredZone;
    }

    /**
     * Clear all previous rules and reload defaults.
     */
    private function resetFirewalldRules(): void
    {
        $this->filesystem->unlinkIfExists(self::DATTO_ZONE_FILE);
        $this->filesystem->unlinkIfExists(self::DIRECT_RULES_FILE);
        $this->firewallCmd->reload();
    }

    /**
     * Add a rule to open a TCP port (if that rule does not exist already).
     */
    public function open(
        int    $port,
        string $zone,
        string $protocol = FirewallCmd::PROTOCOL_TCP,
        bool   $isPermanent = true
    ): void {
        $this->firewallCmd->addPort($port, $protocol, $zone, false);
        if ($isPermanent) {
            $this->firewallCmd->addPort($port, $protocol, $zone, true);
        }
    }

    /**
     * Close TCP port in firewall.
     */
    public function close(
        int $port,
        string $zone,
        string $protocol = FirewallCmd::PROTOCOL_TCP,
        bool $isPermanent = true
    ): void {
        $this->firewallCmd->removePort($port, $protocol, $zone, false);
        if ($isPermanent) {
            $this->firewallCmd->removePort($port, $protocol, $zone, true);
        }
    }

    /**
     * Open the firewall service.
     */
    public function openService(string $service, string $zone, bool $isPermanent = true): void
    {
        $this->firewallCmd->addService($service, $zone, false);
        if ($isPermanent) {
            $this->firewallCmd->addService($service, $zone, true);
        }
    }

    /**
     * Close the firewall service.
     */
    public function closeService(string $service, string $zone, bool $isPermanent = true): void
    {
        $this->firewallCmd->removeService($service, $zone, false);
        if ($isPermanent) {
            $this->firewallCmd->removeService($service, $zone, true);
        }
    }

    /**
     * Applies user's custom firewalld zone changes.
     */
    private function applyUserConfiguredRules(string $zone): void
    {
        $ports = $this->firewalldUserOverrideManager->getOverridePorts($zone);
        $services =  $this->firewalldUserOverrideManager->getOverrideServices($zone);

        foreach ($services as $service) {
            $this->openService($service, $zone);
        }

        foreach ($ports as $el) {
            // $el format is '123/tcp'
            $arr = explode('/', $el);
            $port = intval($arr[0]);
            $protocol = $arr[1];
            $this->open($port, $zone, $protocol);
        }
    }

    /**
     * Applies standard attack rules useful for all device types.
     */
    private function applyDefaultRules(): void
    {
        // Block null packets
        $this->firewallCmd->blockNullPackets();

        // Stop SYN floods
        $this->firewallCmd->stopSYNFloods();

        // Stop XMAS attack
        $this->firewallCmd->stopXMASAttack();

        $this->openLibvirtPortsForBackup();

        $this->firewallCmd->addLogAndDropChain();

        $this->preventNetworkHoppingViaIPForwarding();
    }

    /**
     * Applies server specific rules:
     */
    private function applyServerRules(): void
    {
        if ($this->getCurrentDefaultZone() === self::DATTO_ZONE) {
            $this->open(self::APACHE_CONNECTOR_PORT, self::DATTO_ZONE);
        }

        // Prevent VMs with "simple networking" from communicating with each other
        $this->firewallCmd->addDirectRule('eb', 'filter', 'FORWARD', 1, true, 'DROP', ['--logical-in', 'virbr0']);
        $this->firewallCmd->addDirectRule('eb', 'filter', 'FORWARD', 1, true, 'DROP', ['--logical-out', 'virbr0']);
        $this->firewallCmd->addDirectRule('eb', 'filter', 'FORWARD', 1, true, 'DROP', ['--logical-in', 'virbr1']);
        $this->firewallCmd->addDirectRule('eb', 'filter', 'FORWARD', 1, true, 'DROP', ['--logical-out', 'virbr1']);
    }

    private function applyOnPremRules(): void
    {
        if ($this->getCurrentDefaultZone() !== self::DATTO_ZONE) {
            return;
        }
        // Allow port MDNS/5353 so that 'avahi' can operate.
        $this->openService('mdns', self::DATTO_ZONE);
        $this->enableSamba(true);
        $this->enableNfs(true);
        $this->enableIscsi(true);
    }

    /**
     * Applies direct-to-cloud specific rules. These ports are used for direct-to-cloud agent <-> server communications.
     */
    private function applyDirectToCloudRules(): void
    {
        if ($this->getCurrentDefaultZone() !== self::DATTO_ZONE) {
            return;
        }

        /**
         * The agent communicates mercuryftp over imap/smtp ports. Standard ports are being used in the hopes
         * that one of (80|993|587) will be open on the partner's firewall.
         */
        $this->openService('imaps', self::DATTO_ZONE);
        $this->openService('smtp-submission', self::DATTO_ZONE);
    }

    /**
     * Apply Azure siris specific rules.
     */
    private function applyAzureDeviceRules(): void
    {
        $this->enableSamba(true);
        $this->enableNfs(true);
        $this->enableIscsi(true);
    }

    public function enableSamba(bool $enable): void
    {
        if ($this->getCurrentDefaultZone() !== self::DATTO_ZONE) {
            return;
        }

        try {
            if ($enable) {
                $this->openService('samba', self::DATTO_ZONE);
            } else {
                $this->closeService('samba', self::DATTO_ZONE);
            }
        } catch (Throwable $e) {
            $this->logger->error('FWS0001 error modifying firewall service samba', ['exception' => $e]);
        }
    }

    public function enableSftp(bool $enable): void
    {
        if ($this->getCurrentDefaultZone() !== self::DATTO_ZONE) {
            return;
        }

        try {
            if ($enable) {
                $this->openService('datto-sftp', self::DATTO_ZONE);
            } else {
                $this->closeService('datto-sftp', self::DATTO_ZONE);
            }
        } catch (Throwable $e) {
            $this->logger->error('FWS0002 error modifying firewall service datto-sftp', ['exception' => $e]);
        }
    }

    public function enableNfs(bool $enable): void
    {
        if ($this->getCurrentDefaultZone() !== self::DATTO_ZONE) {
            return;
        }

        try {
            if ($enable) {
                $this->openService('nfs', self::DATTO_ZONE);
                $this->openService('rpc-bind', self::DATTO_ZONE);
                $this->openService('mountd', self::DATTO_ZONE);
                $this->openService('datto-statd', self::DATTO_ZONE);
            } else {
                $this->closeService('nfs', self::DATTO_ZONE);
                $this->closeService('rpc-bind', self::DATTO_ZONE);
                $this->closeService('mountd', self::DATTO_ZONE);
                $this->closeService('datto-statd', self::DATTO_ZONE);
            }
        } catch (Throwable $e) {
            $this->logger->error('FWS0003 error modifying firewall services', ['exception' => $e]);
        }
    }

    public function enableAfp(bool $enable): void
    {
        if ($this->getCurrentDefaultZone() !== self::DATTO_ZONE) {
            return;
        }

        try {
            if ($enable) {
                $this->openService('datto-afp', self::DATTO_ZONE);
            } else {
                $this->closeService('datto-afp', self::DATTO_ZONE);
            }
        } catch (Throwable $e) {
            $this->logger->error('FWS0004 Firewall operation for afp service failed', ['exception' => $e]);
        }
    }

    public function enableMercuryFtp(bool $enable): void
    {
        if ($this->getCurrentDefaultZone() !== self::DATTO_ZONE) {
            return;
        }

        try {
            if ($enable) {
                $this->openService('datto-mercuryftp', self::DATTO_ZONE);
            } else {
                $this->closeService('datto-mercuryftp', self::DATTO_ZONE);
            }
        } catch (Throwable $e) {
            $this->logger->error('FWS0005 Firewall operation for mercury-ftp service failed', ['exception' => $e]);
        }
    }

    public function enableIscsi(bool $enable): void
    {
        if ($this->getCurrentDefaultZone() !== self::DATTO_ZONE) {
            return;
        }

        try {
            if ($enable) {
                $this->openService('iscsi-target', self::DATTO_ZONE);
            } else {
                $this->closeService('iscsi-target', self::DATTO_ZONE);
            }
        } catch (Throwable $e) {
            $this->logger->error('FWS0006 Firewall operation for iscsi-target service failed', ['exception' => $e]);
        }
    }

    /**
     * When IP forwarding is enabled (which it is on our devices) and an IP packet comes into one of the interfaces,
     * if the destination IP address is not an IP address for that interface but if there is a different interface that
     * is in the same network as the destination IP address, the kernel will route the packet to that interface. This
     * could enable an attacker to use the Siris to send packets to machines on a different network that the attacker
     * machine is not on. This diagram shows an example:
     *       _______________
     *      |   Attacker   |
     *      |   Machine    | \
     *      ----------------  \     ______________
     *                         \___|__           |
     *                         | br0 |           |
     *                         ----|--   Siris   |
     *                         ____|__  Device   |
     *                         | br1 |           |
     *                         /---|--           |
     *       _______________  /    |_____________|
     *      |    Victim    | /
     *      |   Machine    |
     *      ----------------
     * The attacker sends out an IP packet that has the IP address of the Victim Machine but the encapsulating Ethernet
     * frame has the MAC address of br0 of the Siris device. When the Siris receives it, it resends the IP packet out
     * the br1 interface to the Victim Machine.
     *
     * To prevent the above attack, we add a series of IP tables rules that prevent packets from being forwarded from
     * one bridge or VLAN interface to a different bridge or VLAN interface.
     */
    private function preventNetworkHoppingViaIPForwarding(): void
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_PREVENT_NETWORK_HOPPING)) {
            $this->logger->debug('FWS0003 Leaving default policy for FORWARD chain unchanged');
            return;
        }

        try {
            $cons = $this->networkManager->getTopLevelConnections();
            // array_filter removes null
            $bridgeNames = array_filter(array_map(fn($con) => $con->isBridge() ? $con->getName(): null, $cons));
            $this->allowForwardForLinks->allowForwardForLinks($bridgeNames);

            // Local virts with NAT networking use IP forwarding to move packets from the br* interface to the virbr*
            // interface. Libvirt adds ACCEPT rules to the FORWARD chain for these virts, so their packets won't reach
            // the DROP action for the FORWARD chain.
            $this->firewallCmd->addDirectRule(
                FirewallCmd::IP_FAMILY_V4,
                FirewallCmd::TABLE_FILTER,
                FirewallCmd::CHAIN_FORWARD,
                1,
                true,
                FirewallCmd::TARGET_DROP,
                []
            );
            $this->logger->debug('FWS0001 Changed default policy for FORWARD chain to drop');
        } catch (Throwable $t) {
            $this->logger->warning('FWS0002 Unable to add rules to prevent network hopping', ['exception' => $t]);
        }
    }

    private function openLibvirtPortsForBackup(): void
    {
        $this->openService('datto-agents', self::LIBVIRT_ZONE);
        $this->openService('datto-mercuryftp', self::LIBVIRT_ZONE);
    }

    public function getCurrentDefaultZone(): string
    {
        return $this->firewallCmd->getDefaultZone();
    }
}
