<?php

namespace Datto\App\Console\Command\Agent\Vss;

use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Windows\VssWriterSetting;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\AssetType;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListVssWritersCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:vss:list';

    protected function configure()
    {
        $this->configureGetAgents();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);

        foreach ($agents as $agent) {
            $table = new Table($output);
            $table->setHeaders(['Agent Key Name', 'VSS Display Name', 'VSS ID', 'Excluded']);

            foreach ($this->getWriters($agent) as $writer) {
                $table->addRow([
                    $agent->getKeyName(),
                    $writer->getDisplayName(),
                    $writer->getId(),
                    $writer->isExcluded() ? 'true' : 'false'
                ]);
            }

            $table->render();
        }

        return 0;
    }

    /**
     * @param Agent $agent
     * @return VssWriterSetting[]
     */
    private function getWriters(Agent $agent): array
    {
        if (!$agent->isType(AssetType::WINDOWS_AGENT)) {
            return [];
        }

        /** @var WindowsAgent $agent */

        return $agent->getVssWriterSettings()->getAll();
    }
}
