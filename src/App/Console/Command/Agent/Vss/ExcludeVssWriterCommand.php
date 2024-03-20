<?php

namespace Datto\App\Console\Command\Agent\Vss;

use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\AssetType;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExcludeVssWriterCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:vss:exclude';

    protected function configure()
    {
        $this
            ->addArgument('writer-id', InputArgument::REQUIRED, 'VSS Writer ID to exclude');

        $this->configureGetAgents();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);
        $vssWriterId = $input->getArgument('writer-id');

        foreach ($agents as $agent) {
            if (!$agent->isType(AssetType::WINDOWS_AGENT)) {
                continue;
            }

            /** @var WindowsAgent $agent */

            $agent->getVssWriterSettings()->excludeWriter($vssWriterId);

            $this->agentService->save($agent);
        }

        return 0;
    }
}
