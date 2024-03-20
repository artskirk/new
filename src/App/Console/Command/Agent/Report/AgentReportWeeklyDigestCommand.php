<?php

namespace Datto\App\Console\Command\Agent\Report;

use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Asset\Agent\AgentService;
use Datto\Feature\FeatureService;
use Datto\Reporting\CustomReportingService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command provides access to CustomReportingService to trigger weekly report mailings
 * Should be invoked once per week by a cron task
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class AgentReportWeeklyDigestCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:report:sendAllWeeklyReports';

    /** @var CustomReportingService */
    private $customReportingService;

    public function __construct(
        CustomReportingService $customReportingService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->customReportingService = $customReportingService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_AGENT_REPORTS];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Send weekly reports for all agents.');
    }

    /**
     * Send weekly report emails for all agents that have custom email configured to do so
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->customReportingService->sendAllWeeklyAgentReports();
        return 0;
    }
}
