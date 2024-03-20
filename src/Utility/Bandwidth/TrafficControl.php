<?php

namespace Datto\Utility\Bandwidth;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Utility to control the outgoing bandwidth on this system.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class TrafficControl implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const TRAFFIC_CONTROL = 'tc';

    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Clear the existing tc rules that are applied to the given network adapter.
     */
    public function clearRules(string $adapterName)
    {
        $process = $this->processFactory
            ->get([
                self::TRAFFIC_CONTROL,
                'qdisc',
                'del',
                'dev',
                $adapterName,
                'root'
            ]);

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning("TCC0002 Unable to clear traffic control rules", [
                'adapter' => $adapterName,
                'errorMessage' => $process->getErrorOutput(),
                'exitCode' => $process->getExitCode()
            ]);
        }
    }

    /**
     * @param string $adapterName The name of the adapter the apply the bandwidth limit to
     * @param int $bandwidthLimitInKbps The bandwidth limit to apply in Kbps
     * @param int $adapterSpeed The speed of the adapter in Mbps
     * @param array $remoteIPs The list of remote IPs to apply the bandwidth limit to
     */
    public function applyBandwidthLimit(string $adapterName, int $bandwidthLimitInKbps, int $adapterSpeed, array $remoteIPs)
    {
        // There is an undocumented lower limit on this value (171), so to be safe, do not scale down past 1000
        $avpkt = $adapterSpeed < 1000 ? 1000 : $adapterSpeed;

        // Set class based queueing, and set some defaults for the root of the tc tree
        $this->processFactory
            ->get([
                self::TRAFFIC_CONTROL,
                'qdisc',
                'add',
                'dev',
                $adapterName,
                'root',
                'handle',
                '1:',
                'cbq',
                'avpkt',
                $avpkt,
                'bandwidth',
                $adapterSpeed . 'mbit'
            ])
            ->mustRun();

        // The main bandwidth limiting rule
        $this->processFactory
            ->get([
                self::TRAFFIC_CONTROL,
                'class',
                'add',
                'dev',
                $adapterName,
                'parent',
                '1:',
                'classid',
                '1:1',
                'cbq',
                'rate',
                $bandwidthLimitInKbps . 'kbit',
                'allot',
                '1500',
                'prio',
                '5',
                'bounded',
                'isolated'
            ])
            ->mustRun();

        foreach ($remoteIPs as $remoteIP) {
            try {
                // Add a filter to limit the bandwidth for data going to each remote IP in our list
                $this->processFactory
                    ->get([
                        self::TRAFFIC_CONTROL,
                        'filter',
                        'add',
                        'dev',
                        $adapterName,
                        'parent',
                        '1:',
                        'protocol',
                        'ip',
                        'prio',
                        '16',
                        'u32',
                        'match',
                        'ip',
                        'dst',
                        $remoteIP,
                        'flowid',
                        '1:1'
                    ])
                    ->mustRun();

                $this->logger->info('TCC0000 Applied bandwidth limit', [
                    'remoteIP' => $remoteIP,
                    'port' => $adapterName,
                    'limitKbps' => $bandwidthLimitInKbps
                ]);
            } catch (Throwable $t) {
                $this->logger->error('TCC0001 Unable to restrict bandwidth for IP', [
                    'remoteIP' => $remoteIP,
                    'port' => $adapterName
                ]);
            }
        }

        // Round robin type, provide each session the chance to send data in turn. It changes its hashing algorithm
        // within an interval. No single session will able to dominate outgoing bandwidth.
        $this->processFactory
            ->get([
                self::TRAFFIC_CONTROL,
                'qdisc',
                'add',
                'dev',
                $adapterName,
                'parent',
                '1:1',
                'sfq',
                'perturb',
                '10'
            ])
            ->mustRun();
    }
}
