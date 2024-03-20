<?php
namespace Datto\App\Console\Command\Asset\Report;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Reporting\CustomReportingService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command for sending Alert Summary email.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class AlertSummaryCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:report:alert:summary:send';

    /** @var CustomReportingService */
    private $customReportingService;

    public function __construct(
        CustomReportingService $customReportingService
    ) {
        parent::__construct();

        $this->customReportingService = $customReportingService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ALERTING_ADVANCED
        ];
    }

    /**
     * Configure the console command.
     */
    protected function configure()
    {
        $this
            ->setDescription('Sends an Alert Summary report email and clears all alerts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->customReportingService->sendAlertSummaryReport();
        return 0;
    }
}
