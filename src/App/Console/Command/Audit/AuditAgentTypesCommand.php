<?php

namespace Datto\App\Console\Command\Audit;

use Datto\Audit\AgentTypeAuditor;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Resource\Sleep;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command reports all of the current agent-types to device-web for auditing purposes.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class AuditAgentTypesCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'audit:agentTypes:reportAll';

    const SECONDS_IN_DAY = 86400;

    /** @var AgentTypeAuditor */
    private $agentTypeAuditor;

    /** @var Sleep */
    private $sleep;

    public function __construct(
        AgentTypeAuditor $agentTypeAuditor,
        Sleep $sleep
    ) {
        parent::__construct();

        $this->agentTypeAuditor = $agentTypeAuditor;
        $this->sleep = $sleep;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Report all current agent-types to device-web for auditing purposes')
            ->addOption('no-sleep', null, InputOption::VALUE_NONE, 'When supplied, execute the script immediately - otherwise the script will sleep between 1 to 24 hours');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shouldSleep = !$input->getOption('no-sleep');
        if ($shouldSleep) {
            $this->sleep();
        }
        $this->agentTypeAuditor->reportAll();
        return 0;
    }

    private function sleep(): void
    {
        $timeout = mt_rand(1, self::SECONDS_IN_DAY);

        $this->logger->debug('AUD0001 Agent-Type Audit is sleeping for ' . $timeout . ' seconds');
        $this->sleep->sleep($timeout);
    }
}
