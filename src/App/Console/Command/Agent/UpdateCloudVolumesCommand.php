<?php

namespace Datto\App\Console\Command\Agent;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\Agent\AgentDataUpdateService;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Cloud\AgentVolumeService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Uploads the current volumes for all agents to device-web.
 * Replaces the 'sendUpdatedVolumesToPortal' legacy command.
 *
 * @author Devon Welcheck <dwelcheck@datto.com>
 */
class UpdateCloudVolumesCommand extends AbstractCommand
{
    protected static $defaultName = 'agent:update:cloud:volumes';

    /** @var AgentService */
    private $agentService;

    /** @var AgentVolumeService */
    private $agentVolumeService;

    /** @var AgentDataUpdateService */
    private $agentDataUpdateService;

    /** @var AgentApiFactory */
    private $agentApiFactory;

    public function __construct(
        AgentService $agentService,
        AgentVolumeService $agentVolumeService,
        AgentDataUpdateService $agentDataUpdateService,
        AgentApiFactory $agentApiFactory
    ) {
        parent::__construct();

        $this->agentService = $agentService;
        $this->agentVolumeService = $agentVolumeService;
        $this->agentDataUpdateService = $agentDataUpdateService;
        $this->agentApiFactory = $agentApiFactory;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_AGENTS];
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->debug('VOL0001 Beginning volume update to portal');
        $agents = $this->agentService->getAllActive();
        foreach ($agents as $agent) {
            // DTC agents don't have an API contract they talk to the device right now, not the other way around
            if (!($agent->isRescueAgent() || $agent->getPlatform()->isAgentless() || $agent->isDirectToCloudAgent())) {
                try {
                    $agentApi = $this->agentApiFactory->createFromAgent($agent);
                    $this->agentDataUpdateService->updateAgentInfo($agent->getKeyName(), $agentApi);
                } catch (Throwable $e) {
                    $this->logger->warning('VOL0003 An error occurred updating agent data. Uploading current data.', ['error' => $e->getMessage()]);
                }
            }

            $this->agentVolumeService->update($agent->getKeyName());
        }
        $this->logger->debug('VOL0004 Volume update to portal complete');
        return 0;
    }
}
