<?php

namespace Datto\App\Console\Command\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DiffMergeService;
use Datto\App\Console\Input\InputArgument;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Snapctl command which sets a key that forces a differential merge on the next backup
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class AgentSetForceDiffMergeCommand extends AbstractAgentCommand
{
    const OS_VOLUME_OPTION = "os";
    const OS_VOLUME_OPTION_SHORT = "o";
    const FAILING_SCREENSHOTS_OPTION = "ifConsistentlyFailingScreenshots";
    const FAILING_SCREENSHOTS_OPTION_SHORT = "s";

    protected static $defaultName = 'agent:set:forcediffmerge';

    /** @var DiffMergeService */
    private $diffMergeService;

    public function __construct(
        DiffMergeService $diffMergeService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->diffMergeService = $diffMergeService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Set the doDiffMerge key (forces a differential merge next backup)')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent for which to set the doDiffMerge key')
            ->addArgument('volume', InputArgument::OPTIONAL, 'The volume GUID to diff merge (defaults to all volumes)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set the doDiffMerge key for all agents (except replicated or archived)')
            ->addOption(self::OS_VOLUME_OPTION, self::OS_VOLUME_OPTION_SHORT, InputOption::VALUE_NONE, 'For the specified agents, set the doDiffMerge key only for the OS volume')
            ->addOption(self::FAILING_SCREENSHOTS_OPTION, self::FAILING_SCREENSHOTS_OPTION_SHORT, InputOption::VALUE_NONE, 'For the specified agents, set the doDiffMerge key only if consistently failing screenshots');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $diffMergeOsVolume = $input->getOption(self::OS_VOLUME_OPTION);
        $diffMergeIfScreenshotsFailing = $input->getOption(self::FAILING_SCREENSHOTS_OPTION);
        $agents = $this->getAgents($input);
        $volumeGuids = $this->getVolumeGuids($input, $agents);

        if ($volumeGuids) {
            if ($diffMergeOsVolume) {
                throw new InvalidArgumentException('Must not specify a volume GUID with the ' . self::OS_VOLUME_OPTION . ' option.');
            }
            if ($diffMergeIfScreenshotsFailing) {
                throw new InvalidArgumentException('Must not specify a volume GUID with the ' . self::FAILING_SCREENSHOTS_OPTION . ' option.');
            }
        }

        foreach ($agents as $agent) {
            if ($agent->getOriginDevice()->isReplicated() || $agent->getLocal()->isArchived()) {
                continue;
            }

            $this->logger->setAssetContext($agent->getKeyName());

            // Important implementation note:
            // This code purposely does NOT use the "$agent->save()" function
            // because the command can be invoked by a background service.
            // The "$agent->save()" function would lead to potential race
            // conditions due to overlapping read/modify/write cycles with
            // other processes, resulting in data loss.  See BCDR-12516.

            try {
                if ($diffMergeOsVolume) {
                    $this->diffMergeService->setDiffMergeOsVolume($agent);
                } elseif ($diffMergeIfScreenshotsFailing) {
                    if ($this->diffMergeService->isDiffMergeNeeded($agent)) {
                        $this->diffMergeService->setDiffMergeOsVolume($agent);
                        $this->logger->info(
                            'DMS0001 Diffmerge scheduled due to multiple failed screenshots'
                        );
                    }
                } elseif (!$volumeGuids) {
                    $this->diffMergeService->setDiffMergeAllVolumes($agent->getKeyName());
                } elseif ($agent->isVolumeDiffMergeSupported()) {
                    $this->diffMergeService->setDiffMergeVolumeGuids($agent, $volumeGuids);
                } else {
                    throw new InvalidArgumentException('Volume diff merges are not supported by agent ' . $agent->getKeyName());
                }
            } catch (Throwable $e) {
                $this->logger->error('DMS0002 Exception', ['message' => $e->getMessage()]);
                $output->writeln('Exception: ' . $e->getMessage());
            }
        }
        return 0;
    }

    /**
     * Gets the list of volume GUIDs to do the diff merge for.
     * Currently, only a single volume GUID may be specified on the command line.
     *
     * @param InputInterface $input
     * @param array $agents
     * @return string[] List of volume GUIDs
     */
    private function getVolumeGuids(InputInterface $input, array $agents): array
    {
        $volume = $input->getArgument('volume');

        if ($volume === null) {
            return [];
        }
        if (count($agents) !== 1) {
            throw new InvalidArgumentException('Must not specify multiple agents when specifying a volume.');
        }
        $agent = reset($agents);
        $agent->getVolume($volume);  // Throw exception if bad volume GUID

        return [$volume];
    }
}
