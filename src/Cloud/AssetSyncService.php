<?php
namespace Datto\Cloud;

use Datto\AppKernel;
use Datto\Billing;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfig;
use Datto\Log\LoggerFactory;
use Datto\Metrics\Offsite\OffsiteMetricsService;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * TODO: DELETE ME
 * Service class used to send snapshots offsite for a particular asset.
 *
 * @author John Roland <jroland@datto.com>
 */
class AssetSyncService
{
    /** @var string The name of the asset. */
    private $assetName;

    /** @var Billing\Service  */
    private $service;

    /** @var AgentConfig  */
    private $agentConfig;

    /** @var DeviceLoggerInterface */
    private $logger;

    private ProcessFactory $processFactory;

    /** @var OffsiteMetricsService */
    private $offsiteMetricsService;

    public function __construct(
        string $assetName,
        Billing\Service $service = null,
        AgentConfig $agentConfig = null,
        DeviceLoggerInterface $logger = null,
        ProcessFactory $processFactory = null,
        OffsiteMetricsService $offsiteMetricsService = null
    ) {
        $this->assetName = $assetName;
        $this->service = $service ?: new Billing\Service();
        $this->agentConfig = $agentConfig ?: new AgentConfig($assetName);
        $this->logger = $logger ?: LoggerFactory::getAssetLogger($assetName);
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->offsiteMetricsService = $offsiteMetricsService ?? AppKernel::getBootedInstance()->getContainer()->get(OffsiteMetricsService::class);
    }

    /**
     * Send the particular snapshot of the asset offsite via speedsync.
     * This method was moved to this class from SpeedSync.
     *
     * @param string $snapshot The id of the snapshot to send offsite.
     * @return bool true if the snapshot was sent offsite. Otherwise, false.
     */
    public function replicateOffsite(string $snapshot): bool
    {
        if ($this->agentConfig->isArchived()) {
            $message = "This point cannot be sent offsite, because agent was archived.";
            $this->logger->info("SYN1419 " . $message);
            throw new Exception($message);
        }

        $this->logger->info('SYN1420 Starting to send snapshot offsite - Checking device service');
        if ($this->service->isOutOfService()) {
            $message = "Device's service expired over 30 days ago; cannot offsite snapshot";
            $this->logger->info("SYN2051 " . $message);
            throw new Exception($message);
        }

        if ($this->service->isLocalOnly()) {
            $message = "Device is local only; cannot offsite snapshot.";
            $this->logger->info("SYN2053 " . $message);
            throw new Exception($message);
        }

        $baseZfsPath = $this->agentConfig->getZfsBase();
        $zfsPath = $baseZfsPath . '/' . $this->assetName;

        $this->logger->info('SYN2052 Executing speedsync mirror for mirror on demand', ['zfsPath' => $zfsPath, 'snapshot' => $snapshot]);

        $success = '/Marking snapshot/';

        $process = $this->processFactory
            ->get(['speedsync', 'mirror', "$zfsPath@$snapshot"])
            ->setTimeout(null);

        $process->run();
        $output = $process->getOutput();
        $result = preg_match($success, $output);

        // Bump the number of queued offsite attempts
        $this->offsiteMetricsService->addQueuedPoint($this->assetName, $snapshot);

        return $result === 1;
    }
}
