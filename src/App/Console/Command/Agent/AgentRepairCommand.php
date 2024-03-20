<?php

namespace Datto\App\Console\Command\Agent;

use Datto\Agent\RepairHandler;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\RepairService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class AgentRepairCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:repair';

    private RepairHandler $repairHandler;
    private RepairService $repairService;
    private FeatureService $featureService;

    public function __construct(AgentService $agentService, RepairService $repairService, FeatureService $featureService)
    {
        parent::__construct($agentService);
        $this->repairHandler = new RepairHandler(); // RepairHandler is in web/lib and not available from the container
        $this->repairService = $repairService;
        $this->featureService = $featureService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_AGENT_BACKUPS];
    }

    protected function configure()
    {
        $this->setDescription('Repairs an agent.')
            ->setHelp('Repairs an existing agent.')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The keyName of the agent to repair.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Repair all agents.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->repairHandler->setLogger($this->logger);
        $agents = $this->getAgents($input);

        // todo Repair should be cleaned up as part of BCDR-17619

        foreach ($agents as $agent) {
            $this->logger->setAssetContext($agent->getKeyName());

            $keyName = $agent->getKeyName();

            if ($this->featureService->isSupported(FeatureService::FEATURE_NEW_REPAIR)) {
                try {
                    $this->repairService->repair($keyName);
                    $this->logger->info('AGT3000 Agent repaired successfully.');
                } catch (Throwable $exception) {
                    $this->logger->error('AGT5000 Unexpected error occurred during agent repair.', ['exception' => $exception]);
                }
            } else {
                $connectionName = $agent instanceof AgentlessSystem ? $agent->getEsxInfo()->getConnectionName() : '';
                $repairResult = $this->repairHandler->repair($keyName, $connectionName);

                // keeping these echos and log messages around for backwards consistency
                if ($repairResult == RepairHandler::REPAIR_ERROR_IN_PROGRESS_KEY_FILE) {
                    $output->writeln("A repair is already in progress for this agent.");
                    $output->writeln("Rename \"$keyName.key.repairBackup\" to \"$keyName.key\" to force a repair anyway.");
                    $repairResult = RepairHandler::REPAIR_ERROR_IN_PROGRESS;
                } elseif ($repairResult == RepairHandler::REPAIR_ERROR_IN_PROGRESS_SHM) {
                    $output->writeln("A repair is already in progress for this agent.");
                    $output->writeln("Remove /dev/shm/$keyName.repairBackup to force a repair anyway.");
                    $repairResult = RepairHandler::REPAIR_ERROR_IN_PROGRESS;
                } elseif ($repairResult == RepairHandler::REPAIR_ERROR_IN_PROGRESS_LOCK) {
                    $output->writeln("A repair is already in progress for this agent.");
                    $output->writeln("Remove /dev/shm/$keyName.repairInProgress to force a repair anyway (not recommended).");
                    $repairResult = RepairHandler::REPAIR_ERROR_IN_PROGRESS;
                }

                switch ($repairResult) {
                    case RepairHandler::REPAIR_SUCCESS:
                        $this->logger->info("AGT3000 Agent repaired successfully.");
                        break;
                    case RepairHandler::REPAIR_ERROR_DNAS:
                        $this->logger->error("AGT3001 Cannot repair communications. Repair is not a valid operation on a Datto NAS device.");
                        break;
                    case RepairHandler::REPAIR_ERROR_OUT_OF_SERVICE:
                        $this->logger->info("AGT2000 This device is out of service and cannot repair agents anymore.");
                        break;
                    case RepairHandler::REPAIR_ERROR_IN_PROGRESS:
                        $this->logger->error("AGT3003 Cannot repair communications. Repair already in progress.");
                        break;
                    case RepairHandler::REPAIR_ERROR_AGENT_DOES_NOT_EXIST:
                        $this->logger->error("AGT3004 Cannot repair communications. Agent does not exist.");
                        break;
                    case RepairHandler::REPAIR_ERROR_RESCUE_AGENT:
                        $this->logger->error("AGT3014 Cannot repair communications. Repair is not valid on rescue agents.");
                        break;
                    case RepairHandler::REPAIR_ERROR_REPLICATED_AGENT:
                        $this->logger->error("AGT3015 Cannot repair communications. Repair is not valid on replicated agents.");
                        break;
                    case RepairHandler::REPAIR_ERROR_UNKNOWN:
                    default:
                        $this->logger->error("AGT3005 Cannot repair communications.", ['errorCode' => $repairResult]);
                        break;
                }
            }
        }
        return 0;
    }
}
