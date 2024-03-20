<?php

namespace Datto\App\Console\Command\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\MachineIdService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command will display the machine id for all agents on the device
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class AgentShowMachineIdCommand extends Command
{
    protected static $defaultName = 'agent:machineId:show';

    /** @var AgentService */
    private $agentService;

    /** @var MachineIdService */
    private $machineIdService;

    public function __construct(
        AgentService $agentService,
        MachineIdService $machineIdService
    ) {
        parent::__construct();

        $this->agentService = $agentService;
        $this->machineIdService = $machineIdService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Gets the machine ID for each agent on the device');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->agentService->getAll();

        $table = new Table($output);
        $table->setHeaders(array(
            'Name',
            'Machine ID'
        ));

        foreach ($agents as $agent) {
            $name = $agent->getName();

            try {
                $machineId = $this->machineIdService->getMachineId($agent);
            } catch (\Exception $e) {
                $machineId = 'UNKNOWN';
            }

            $table->addRow(array(
                $name,
                $machineId
            ));
        }

        $table->render();
        return 0;
    }
}
