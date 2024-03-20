<?php

namespace Datto\App\Console\Command\Agent\Rescue;

use Datto\Asset\Agent\Rescue\RescueAgentService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Boots up any unencrypted rescue agents that were powered on according to VM configs.  Intended to be run at boot.
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class ReconcileRescueAgentBootStatesCommand extends Command
{
    protected static $defaultName = 'agent:rescue:reconcileBootStates';

    /** @var RescueAgentService */
    private $rescueAgentService;

    public function __construct(
        RescueAgentService $rescueAgentService
    ) {
        parent::__construct();

        $this->rescueAgentService = $rescueAgentService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Boot any unencrypted rescue agents attached to the system that may be down due to unexpected system shutdown.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->rescueAgentService->reconcileBootStates();
        return 0;
    }
}
