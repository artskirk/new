<?php

namespace Datto\App\Console\Command\Asset\Report;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Reporting\CustomReportingService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command provides access to CustomReportingService to trigger daily asset log report emails
 * should be triggered once per day by a cron job
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class AssetReportLogsCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:report:sendAllAssetLogReports';

    /** @var CustomReportingService */
    private $customReportingService;

    /**
     * @param CustomReportingService $customReportingService
     */
    public function __construct(
        CustomReportingService $customReportingService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->customReportingService = $customReportingService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Send daily log report for all assets.');
    }

    /**
     * Send all daily logs reports for all assets which have custom email configured
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->customReportingService->sendAllAssetLogsAndReports();
        return 0;
    }
}
