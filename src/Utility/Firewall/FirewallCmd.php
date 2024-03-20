<?php

namespace Datto\Utility\Firewall;

use Datto\Common\Resource\Process;
use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Provides a thin wrapper around the `firewall-cmd` utility for managing firewalld
 */
class FirewallCmd implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const PROTOCOL_TCP = 'tcp';
    public const PROTOCOL_UDP = 'udp';
    public const CHAIN_INPUT = 'INPUT';
    public const CHAIN_FORWARD = 'FORWARD';
    public const TARGET_REJECT = 'REJECT';
    public const TARGET_ACCEPT = 'ACCEPT';
    public const TARGET_DROP = 'DROP';
    public const TABLE_FILTER = 'filter';
    public const IP_FAMILY_V4 = 'ipv4';
    const LOG_AND_DROP_CHAIN_NAME = 'log_drop';

    /** @var int The number of seconds to use for a window when rate limiting new TCP connections. */
    const RATE_LIMIT_WINDOW_SECS = 90;

    /** @var int The number of new TCP connections allowed in a window. */
    const RATE_LIMIT_MAX_CONNECTIONS = 210;

    private ProcessFactory $processFactory;

    public function __construct(
        ProcessFactory $processFactory
    ) {
        $this->processFactory = $processFactory;
    }

    /**
     * Reloads the firewall configuration from disk, discarding any temporary configurations made
     */
    public function reload(): void
    {
        $this->firewallCmd(['--reload']);
    }

    /**
     * Sets the default zone for any interface not explicitly assigned to a different zone
     * --set-default-zone is a runtime and permanent change.
     */
    public function setDefaultZone(string $zone): void
    {
        $this->firewallCmd(['--set-default-zone', $zone]);
    }

    /**
     * Gets the default zone from firewalld
     */
    public function getDefaultZone(): string
    {
        $out=$this->firewallCmd(['--get-default-zone']);
        return trim(explode("\n", $out->getOutput())[0]);
    }

    /**
     * Adds a firewalld service to firewall , opening up the ports for that service.
     * @param string $service
     * @param string $zone The zone in which the service is to be added.
     * @param bool $isPermanent  Whether the rule persists across reboot.
     */
    public function addService(string $service, string $zone, bool $isPermanent): void
    {
        $rule = ["--zone=$zone", "--add-service", $service];
        if ($isPermanent) {
            $rule  = array_merge(["--permanent"], $rule);
        }
        $this->firewallCmd($rule);
    }

    /**
     * Removes a firewalld service from the firewall configuration, closing the associated ports.
     * @param string $service
     * @param string $zone The zone in which the service is to be removed.
     * @param bool $isPermanent Whether the rule persists across reboot.
     */
    public function removeService(string $service, string $zone, bool $isPermanent): void
    {
        $rule = ["--zone=$zone", "--remove-service", $service];
        if ($isPermanent) {
            $rule  = array_merge(["--permanent"], $rule);
        }
        $this->firewallCmd($rule);
    }

    /**
     * Opens a port for traffic of the given protocol.
     * @param int $port The port to open
     * @param string $protocol The protocol (@see FirewallCmd::PROTOCOL_TCP, FirewallCmd::PROTOCOL_UDP)
     * @param string $zone The zone in which the port is to be opened.
     * @param bool $isPermanent Whether the rule persists across reboot.
     */
    public function addPort(int $port, string $protocol, string $zone, bool $isPermanent): void
    {
        $rule = ["--zone=$zone", "--add-port", "$port/$protocol"];
        if ($isPermanent) {
            $rule  = array_merge(["--permanent"], $rule);
        }
        $this->firewallCmd($rule);
    }

    /**
     * Closes an open firewall port for traffic of the given protocol.
     * @param int $port The port to close.
     * @param string $protocol The protocol (@see FirewallCmd::PROTOCOL_TCP, FirewallCmd::PROTOCOL_UDP)
     * @param string $zone The zone in which the port is to be blocked.
     * @param bool $isPermanent Whether the rule persists across reboot.
     */
    public function removePort(int $port, string $protocol, string $zone, bool $isPermanent): void
    {
        $rule = ["--zone=$zone", "--remove-port", "$port/$protocol"];
        if ($isPermanent) {
            $rule  = array_merge(["--permanent"], $rule);
        }
        $this->firewallCmd($rule);
    }

    /**
     * Changes the firewalld zone for a network interface.
     */
    public function changeFirewallZoneForInterface(string $interface, string $zone): void
    {
        $this->firewallCmd(["--zone=$zone", "--change-interface=$interface"]);
    }

    /**
     * Adds a 'direct' rule to firewalld.
     * https://firewalld.org/documentation/man-pages/firewalld.direct.html
     * @param string $ipFamily The IP family where the rule will be added. ipv4/ipv6/eb.
     * @param string $table The table name where the chain will be created. filter/mangle/nat.
     * @param string $chain The name of the chain where the rule will be added. INPUT/OUTPUT/FORWARD.
     * @param int $priority The priority is used to order rules.
     * @param bool $isPermanent Whether rule should be permanent as well.
     * @param string $target The next target to jump to ACCEPT/DROP/REJECT
     * @param array $args Other needed arguments.
     */
    public function addDirectRule(
        string $ipFamily,
        string $table,
        string $chain,
        int $priority,
        bool $isPermanent,
        string $target,
        array $args
    ) : void {
        $cmdArr = ['--direct', '--add-rule', $ipFamily, $table, $chain, strval($priority)];
        $cmdArr  = array_merge($cmdArr, $args, ['-j', $target]);
        $this->firewallCmd($cmdArr);

        if ($isPermanent) {
            $cmdArr  = array_merge([ '--permanent' ], $cmdArr);
            $this->firewallCmd($cmdArr);
        }
    }

    public function setRateLimiting(
        int    $port,
        int    $periodInSeconds = self::RATE_LIMIT_WINDOW_SECS,
        int    $hitCount = self::RATE_LIMIT_MAX_CONNECTIONS,
        int    $priority = 1,
        string $ipFamily = FirewallCmd::IP_FAMILY_V4,
        string $chain = FirewallCmd::CHAIN_INPUT,
        string $table = FirewallCmd::TABLE_FILTER,
        string $protocol = FirewallCmd::PROTOCOL_TCP
    ): void {

        // These rules apply to traffic going to --dport and with state NEW (-m state --state NEW).
        // It uses the recent module (-m recent) to define a list called "https". This list contains
        // (srcip, hitcount, timestamp) tuples. The 'update' function of the recent module will search list "https"
        // for the tuple where srcip equals the current packet's source ip. If the rate defined by --hitcount and
        // --seconds has been exceeded the packet is sent to the $target chain. If the current packet does not
        // exceed the rate, evaluation falls through to the next rule which increments hitcount in the tuple.
        // See https://manpages.debian.org/bullseye/iptables/iptables-extensions.8.en.html#recent for documentation
        // on the recent module. Adds temporary and permanent rules.

        $setCmdRule = [
            '--direct', '--add-rule', $ipFamily, $table, $chain, $priority, '-p', $protocol, '--dport', $port,
            '-m', 'state', '--state', 'NEW', '-m', 'recent', '--set'
        ];
        $this->firewallCmd($setCmdRule);
        $setCmdRule = array_merge(['--permanent'], $setCmdRule);
        $this->firewallCmd($setCmdRule);

        $rejectCmdRule = [
            '--direct', '--add-rule', $ipFamily, 'filter', $chain, $priority, '-p', $protocol, '--dport', $port,
            '-m', 'state', '--state', 'NEW', '-m', 'recent', '--update', '--seconds', $periodInSeconds,
            '--hitcount', $hitCount, '-j', FirewallCmd::LOG_AND_DROP_CHAIN_NAME
        ];
        $this->firewallCmd($rejectCmdRule);
        $rejectCmdRule = array_merge(['--permanent'], $rejectCmdRule);
        $this->firewallCmd($rejectCmdRule);
    }

    /**
     * Adds a chain that logs details of packets that are dropped.
     * This logging is throttled and logs example packets periodically.
     * Adds temporary and permanent rules.
     */
    public function addLogAndDropChain(): void
    {
        $addChainRule = [
            '--direct', '--add-chain', FirewallCmd::IP_FAMILY_V4, FirewallCmd::TABLE_FILTER,
            FirewallCmd::LOG_AND_DROP_CHAIN_NAME
        ];
        $this->firewallCmd($addChainRule);
        $addChainRule = array_merge(['--permanent'], $addChainRule);
        $this->firewallCmd($addChainRule);


        // Log the drop.  An exemplar packet is logged once per ~5 minutes. To view these log entries, run:
        //   grep siris-os-2-drop-packet /var/log/kern.log
        $logDropRule = [
            '--direct', '--add-rule', FirewallCmd::IP_FAMILY_V4, FirewallCmd::TABLE_FILTER,
            FirewallCmd::LOG_AND_DROP_CHAIN_NAME, 1,
            '-m', 'limit', '--limit', '12/hour', '-j', 'LOG', '--log-prefix',  'siris-os-2-drop-packet'
        ];
        $this->firewallCmd($logDropRule);
        $logDropRule = array_merge(['--permanent'], $logDropRule);
        $this->firewallCmd($logDropRule);

        // Here comes the drop
        $dropRule = [
            '--direct', '--add-rule', FirewallCmd::IP_FAMILY_V4, FirewallCmd::TABLE_FILTER,
            FirewallCmd::LOG_AND_DROP_CHAIN_NAME, 1, '-j', 'DROP'
        ];
        $this->firewallCmd($dropRule);
        $dropRule = array_merge(['--permanent'], $dropRule);
        $this->firewallCmd($dropRule);
    }

    public function blockNullPackets(): void
    {
        $this->addDirectRule(
            FirewallCmd::IP_FAMILY_V4,
            FirewallCmd::TABLE_FILTER,
            FirewallCmd::CHAIN_INPUT,
            1,
            true,
            FirewallCmd::TARGET_DROP,
            ['-p', 'tcp', '--tcp-flags', 'ALL', 'NONE']
        );
    }

    public function stopSYNFloods(): void
    {
        $this->addDirectRule(
            FirewallCmd::IP_FAMILY_V4,
            FirewallCmd::TABLE_FILTER,
            FirewallCmd::CHAIN_INPUT,
            1,
            true,
            FirewallCmd::TARGET_DROP,
            ['-p', 'tcp', '!', '--syn', '-m', 'state', '--state','NEW']
        );
    }

    public function stopXMASAttack(): void
    {
        $this->addDirectRule(
            FirewallCmd::IP_FAMILY_V4,
            FirewallCmd::TABLE_FILTER,
            FirewallCmd::CHAIN_INPUT,
            1,
            true,
            FirewallCmd::TARGET_DROP,
            ['-p', 'tcp', '--tcp-flags', 'ALL', 'ALL']
        );
    }

    public function forwardBetweenSameInterface(string $interface): void
    {
        $this->addDirectRule(
            FirewallCmd::IP_FAMILY_V4,
            FirewallCmd::TABLE_FILTER,
            FirewallCmd::CHAIN_FORWARD,
            1,
            true,
            FirewallCmd::TARGET_ACCEPT,
            [
                '-i', $interface,
                '-o', $interface
            ]
        );
    }

    /**
     * Wrapper around the ProcessFactory to reduce code duplication
     * @param array $args The arguments to `firewall-cmd`
     * @return Process The results of the Process that was run
     */
    private function firewallCmd(array $args): Process
    {
        $this->logger->debug('FWC0001 executing firewall-cmd', ['args' => $args]);
        return $this->processFactory->get(array_merge(['firewall-cmd'], $args))->mustRun();
    }
}
