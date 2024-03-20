<?php

namespace Datto\App\Console\Command\Agent\Report;

use Datto\Asset\Agent\AgentService;
use Datto\Reporting\Aggregated\ReportService;
use Datto\Reporting\Aggregated\ReportSummary;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print out a report summary of an agent.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ReportSummaryCommand extends Command
{
    protected static $defaultName = 'agent:report:summary';

    /** @var AgentService */
    private $agentService;

    /** @var ReportService */
    private $reportService;

    public function __construct(
        ReportService $reportService,
        AgentService $agentService
    ) {
        parent::__construct();

        $this->reportService = $reportService;
        $this->agentService = $agentService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Backup and screenshot summary.')
            ->addArgument('asset', InputArgument::REQUIRED, 'Asset to target.')
            ->addOption('timeframe', null, InputOption::VALUE_REQUIRED, 'Specify timeframe (eg. "week")');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('asset');
        $timeframe = $input->getOption('timeframe');

        $agent = $this->agentService->get($assetKey);
        if ($agent->getOriginDevice()->isReplicated()) {
            throw new Exception('Replicated agents do not have reports.');
        }

        $earliestEpoch = $timeframe ? $this->reportService->getEpochFromTimeframe($timeframe) : null;
        $summary = $this->reportService->getSummary($agent, $earliestEpoch);
        $this->printSummary($summary, $output);
        return 0;
    }

    /**
     * @param ReportSummary $summary
     * @param OutputInterface $output
     */
    private function printSummary(ReportSummary $summary, OutputInterface $output): void
    {
        $table = new Table($output);

        $table->setHeaders([
            '',
            'Successful',
            'Total',
            'Percentage'
        ]);

        $table->addRow([
            'Screenshots',
            $summary->getSuccessfulScreenshotCount(),
            $summary->getScreenshotCount(),
            $this->getPercentage($summary->getSuccessfulScreenshotCount(), $summary->getScreenshotCount())
        ]);

        $table->addRow([
            'Forced Backups',
            $summary->getSuccessfulForcedBackupCount(),
            $summary->getForcedBackupCount(),
            $this->getPercentage($summary->getSuccessfulForcedBackupCount(), $summary->getForcedBackupCount())
        ]);

        $table->addRow([
            'Scheduled Backups',
            $summary->getSuccessfulScheduledBackupCount(),
            $summary->getScheduledBackupCount(),
            $this->getPercentage($summary->getSuccessfulScheduledBackupCount(), $summary->getScheduledBackupCount())
        ]);

        $table->render();
    }

    /**
     * @param int $a
     * @param int $b
     * @return null|string
     */
    private function getPercentage(int $a, int $b)
    {
        if ($b === 0) {
            return null;
        }

        return sprintf("%.2f%%", ($a / $b) * 100);
    }
}
