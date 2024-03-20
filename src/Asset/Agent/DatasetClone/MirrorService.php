<?php

namespace Datto\Asset\Agent\DatasetClone;

use Datto\Asset\Agent\AgentService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Iscsi\Initiator;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Utility\Screen;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Handles using zsender to stream incremental changes
 *
 * @author Justin Giacobbi <jgiacobbi@datto.com>
 */
class MirrorService
{
    const SCREEN_PREFIX = "bmr-mirror";
    const AGENT_BASE = "homePool/home/agents";
    const AGENT_MOUNT = "/home/agents";

    const ZSEND_LOG_TEMPLATE = "/datto/config/keys/%s-%s-%s-%s.zsendCode";
    const IQN_FORMAT = "iqn.2002-12.com.datto:%s";
    /** @var Collector|null */
    private $collector;

    private Sleep $sleep;
    private ProcessFactory $processFactory;
    private ?DeviceLoggerInterface $logger;
    private Filesystem $filesystem;
    private Initiator $initiator;
    private AgentService $agentService;
    private Screen $screen;

    public function __construct(
        ProcessFactory $processFactory,
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        Initiator $initiator,
        AgentService $agentService,
        Screen $screen,
        Collector $collector,
        Sleep $sleep
    ) {
        $this->processFactory = $processFactory;
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->initiator = $initiator;
        $this->agentService = $agentService;
        $this->screen = $screen;
        $this->collector = $collector;
        $this->sleep = $sleep;
    }

    /**
     * Invokes zsender to mirror the latest changes from the zpool to the block
     * device shared via iSCSI from the BMR environment
     *
     * @param string $bmrIP the IP of the BMR device
     * @param string $agent the agent to mirror
     * @param string $guid the guid of the volume to mirror
     * @param string $bmrSnapshot the latest recovery point mirrored to the BMR for this volume
     * @param string $targetSnapshot
     *      The agent's recovery point you would like to mirror. If not specified, the latest
     *      snapshot will be used.
     * @return bool
     */
    public function start($bmrIP, $agent, $guid, $bmrSnapshot, $targetSnapshot = null)
    {
        $this->collector->increment(Metrics::RESTORE_BMR_MIRROR_STARTED);

        if ($this->screen->isScreenRunning($this->getScreenLabel($bmrIP, $agent))) {
            throw new Exception('A volume is already being mirrored for this agent');
        }

        $targetName = $this->targetName($guid);

        try {
            $this->discoverTarget($bmrIP, $guid);
            $this->initiator->loginTarget($targetName);
            $this->sleep->sleep(1); //give the block device a second to load

            // get the latest snapshot if one wasn't passed in
            if (!isset($targetSnapshot)) {
                $targetSnapshot = $this->getLatestSnap($agent);
            }

            $this->writeDiffs(
                $bmrIP,
                $agent,
                $targetName,
                $bmrSnapshot,
                $guid,
                $targetSnapshot
            );
        } catch (Exception $e) {
            $this->initiator->logoutTarget($targetName);
            $this->logger->error('MIR0001 Failed to start mirroring', ['exception' => $e]);
            throw $e;
        }

        $screenRunning = false;

        // Give the screen 10 seconds to start
        for ($i = 0; $i < 10 && !$screenRunning; $i++) {
            $screenRunning = $this->screen->isScreenRunning($this->getScreenLabel($bmrIP, $agent));
            $this->sleep->sleep(1);
        }

        return $screenRunning;
    }

    /**
     * Returns status on a mirror in progress.
     *
     * @param string $bmrIP the IP of the BMR device
     * @param string $agent the agent to mirror
     * @param string $guid the guid to mirror
     * @param string $point the point to mirror
     *
     * @return array
     */
    public function status($bmrIP, $agent, $guid, $point)
    {
        $screenRunning = $this->running($bmrIP, $agent);

        $zsendLog = $this->getZsendLog($bmrIP, $agent, $guid, $point);
        $zsendLogSuccess = $this->filesystem->exists($zsendLog) && trim($this->filesystem->fileGetContents($zsendLog)) === "0";

        return array(
            'running' => $screenRunning,
            'success' => $zsendLogSuccess
        );
    }

    /**
     * Returns whether a mirror is running for this bmrIP and agent.
     *
     * @param string $bmrIP
     * @param string $agent
     * @return bool
     */
    public function running($bmrIP, $agent)
    {
        return $this->screen->isScreenRunning($this->getScreenLabel($bmrIP, $agent));
    }

    /**
     * Cleans up the iscsi leftovers after a mirror is complete
     *
     * @param string $bmrIP the IP of the BMR device
     *
     * @return void
     */
    public function cleanup($bmrIP)
    {
        $this->initiator->logoutIP($bmrIP);
        $this->initiator->clearDiscoveryDatabaseEntry($bmrIP);

        $zsendLogPattern = $this->getZsendLog($bmrIP, '*', '*', '*');
        foreach ($this->filesystem->glob($zsendLogPattern) as $zsendLog) {
            $this->filesystem->unlink($zsendLog);
        }
    }

    /**
     * Returns the path to the log file containing the exit code of
     * the zsend command using the given parameters.
     *
     * @param string $bmrIP
     * @param string $agent
     * @param string $guid
     * @param string $point
     * @return string
     */
    public function getZsendLog($bmrIP, $agent, $guid, $point)
    {
        return sprintf(self::ZSEND_LOG_TEMPLATE, $bmrIP, $agent, $guid, $point);
    }

    private function discoverTarget($bmrIP, $guid): void
    {
        $targets = $this->initiator->discoverByIP($bmrIP);
        $targets = preg_grep("#$guid#", $targets);

        if (count($targets) !== 1) {
            $this->logger->error('MIR0010 Unexpected number of targets', [
                'targets' => $targets,
                'count' => count($targets)
            ]);
            throw new Exception('Unexpected number of targets');
        }
    }

    private function writeDiffs($bmrIP, $agent, $targetName, $latestMirroredSnap, $guid, $targetSnapshot): void
    {
        $this->validateSnapshot($agent, $targetSnapshot);

        $blockDevice = $this->getBlockDevice($targetName);

        if ($targetSnapshot <= $latestMirroredSnap) {
            $this->logger->error('MIR0040 Volume is already up to date', [
                'targetSnapshot' => $targetSnapshot,
                'latestMirroredSnap' => $latestMirroredSnap
            ]);
            throw new Exception('Volume is already up to date');
        }

        $partStart = $this->getPartitionStart(self::AGENT_MOUNT . "/$agent/$guid.datto");
        $writeSize = $this->getSize(self::AGENT_MOUNT . "/$agent/$guid.datto") - $partStart;
        $zsendLog = $this->getZsendLog($bmrIP, $agent, $guid, $targetSnapshot);

        if ($this->filesystem->exists($zsendLog)) {
            $this->filesystem->unlink($zsendLog);
        }

        // This script is executed in a screen to perform the actual mirroring. zsend_parse
        // extracts the differences between a datto image at two points in time and then
        // zsend_write writes those differences to the iscsi-exposed block device. The offsets
        // are to correct for the difference between the partition start on datto images
        // (currently defined by mksparse), and the partition start on a directly exposed
        // partition (0).
        $cmd = implode(' ', [
            'echo "Mirroring...";',
            'zsend_parse -b',
            escapeshellarg(self::AGENT_BASE . "/$agent@$latestMirroredSnap"),
            escapeshellarg(self::AGENT_BASE . "/$agent@$targetSnapshot"),
            escapeshellarg($guid . '.datto'),
            '-B',
            '-s',
            escapeshellarg($partStart),
            '-l',
            escapeshellarg($writeSize),
            '| pv | zsend_write',
            escapeshellarg($blockDevice),
            '-s',
            escapeshellarg("-$partStart"),
            '; echo $? >',
            escapeshellarg($zsendLog),
            '; sync',
            escapeshellarg($blockDevice)
        ]);

        $this->logger->info('MIR0041 Writing diffs', ['cmd' => $cmd]);
        $label = $this->getScreenLabel($bmrIP, $agent);

        if ($this->screen->isScreenRunning($label)) {
            throw new Exception("A volume is already being mirrored for this agent");
        } else {
            $this->screen->runInBackgroundWithoutEscaping($cmd, $label, true);
        }
    }

    private function getScreenLabel($bmrIP, $agent): string
    {
        return self::SCREEN_PREFIX . "-$bmrIP-$agent";
    }

    private function getBlockDevice($targetName)
    {
        $devices = $this->initiator->getBlockDeviceOfTarget($targetName);

        if (count($devices) < 1) {
            $this->logger->error('MIR0025 No LUNs found', [
                'targetName' => $targetName
            ]);
            throw new Exception('No LUNs found');
        }

        if (count($devices) > 1) {
            $this->logger->warning('MIR0020 Unexpected number of luns', ['numberOfLuns' => count($devices)]);
        }

        return $devices[0];
    }

    private function getSize($blockDevice): string
    {
        $process = $this->processFactory->get(['ls', '-las', $blockDevice]);
        $process->mustRun();

        $out = $process->getOutput();
        $size = explode(" ", $out);
        $size = $size[5];
        return $size;
    }

    private function getPartitionStart($file)
    {
        $process = $this->processFactory->get(['dattopart', '--path', $file, '--command', 'print']);
        $process->mustRun();

        $partitionInfo = json_decode(trim($process->getOutput()), true);

        $validPartitionOutput =
            isset($partitionInfo["label"]["partitions"][0]["start_sector"])
            && is_numeric($partitionInfo["label"]["partitions"][0]["start_sector"])
            && isset($partitionInfo["sector_size_bytes"])
            && is_numeric($partitionInfo["sector_size_bytes"]);

        if (!$validPartitionOutput) {
            $this->logger->error('MIR0060 Unexpected Disk Information', ['partitionInfo' => $partitionInfo]);
            throw new Exception('Unexpected disk information');
        }

        if (count($partitionInfo["label"]["partitions"]) !== 1) {
            $this->logger->error('MIR0070 Unexpected number of partitions on source image', [
                'partitionInfo' => $partitionInfo
            ]);
            throw new Exception('Unexpected number of partitions on source image');
        }

        $startSector = $partitionInfo["label"]["partitions"][0]["start_sector"];
        $sectorSize = $partitionInfo["sector_size_bytes"];

        return ($startSector * $sectorSize);
    }

    private function getLatestSnap($agent)
    {
        return max(
            $this->agentService
            ->get($agent)
            ->getDataset()
            ->listSnapshots()
        );
    }

    private function validateSnapshot($agent, $snapshot): void
    {
        $snapshots = $this->agentService
            ->get($agent)
            ->getDataset()
            ->listSnapshots();

        if (!in_array($snapshot, $snapshots)) {
            $this->logger->error('MIR0041 Snapshot does not exist on agent', [
                'agent' => $agent,
                'snapshot' => $snapshot
            ]);
            throw new Exception('Snapshot does not exist on agent');
        }
    }

    private function targetName($guid): string
    {
        return sprintf(self::IQN_FORMAT, $guid);
    }
}
