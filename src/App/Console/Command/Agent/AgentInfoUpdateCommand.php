<?php

namespace Datto\App\Console\Command\Agent;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentDataUpdateService;
use Datto\Asset\Agent\AgentDataUpdateStatus;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Config\AgentShmConfigFactory;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Command to force an update of agent data.
 * @author Devon Welcheck <dwelcheck@datto.com>
 */
class AgentInfoUpdateCommand extends AbstractCommand
{
    protected static $defaultName = 'agent:update';

    /** @var AgentDataUpdateService */
    private $agentDataUpdateService;

    /** @var AgentService */
    private $agentService;

    /** @var AgentShmConfigFactory */
    private $agentShmConfigFactory;

    /** @var AgentApiFactory */
    private $agentApiFactory;

    public function __construct(
        AgentDataUpdateService $agentDataUpdateService,
        AgentService $agentService,
        AgentShmConfigFactory $agentShmConfigFactory,
        AgentApiFactory $agentApiFactory
    ) {
        parent::__construct();

        $this->agentDataUpdateService = $agentDataUpdateService;
        $this->agentService = $agentService;
        $this->agentShmConfigFactory = $agentShmConfigFactory;
        $this->agentApiFactory = $agentApiFactory;
    }

    /**
     * @inheritDoc
     */
    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_AGENT_BACKUPS];
    }

    protected function configure()
    {
        $this
            ->setDescription('Updates the agent info for a given agent.')
            ->addArgument('agent', InputArgument::REQUIRED, 'The identifier of the agent to update.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentKey = $input->getArgument('agent');

        $shmConfig = $this->agentShmConfigFactory->create($agentKey);
        $updateStatus = new AgentDataUpdateStatus();

        $updateStatus->setStatus(AgentDataUpdateStatus::STATUS_IN_PROGRESS);
        $shmConfig->saveRecord($updateStatus);

        try {
            $agent = $this->agentService->get($agentKey);
            $agentApi = $this->agentApiFactory->createFromAgent($agent);
            $this->agentDataUpdateService->updateAgentInfo($agentKey, $agentApi);
            $updateStatus->setStatus(AgentDataUpdateStatus::STATUS_SUCCESS);
        } catch (Throwable $e) {
            $updateStatus->setStatus(AgentDataUpdateStatus::STATUS_FAILED);
        }

        $shmConfig->saveRecord($updateStatus);
        return 0;
    }
}
