<?php

namespace Datto\App\Console\Command\Share;

use Datto\App\Console\Command\AbstractShareCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Share\ShareService;
use Datto\Feature\FeatureService;
use Datto\Reporting\NasShareGrowthReportGenerator;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShareNasGrowthReport extends AbstractShareCommand
{
    protected static $defaultName = "share:nas:growth-report";

    private NasShareGrowthReportGenerator $growthReportGenerator;

    public function __construct(
        CommandValidator $commandValidator,
        ShareService $shareService,
        NasShareGrowthReportGenerator $growthReportGenerator
    ) {
        parent::__construct($commandValidator, $shareService);
        $this->growthReportGenerator = $growthReportGenerator;
    }

    protected function configure(): void
    {
        $this->setDescription("Generate a NAS growth report and send it");
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_SHARE_GROWTH_REPORT];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->growthReportGenerator->sendAllGrowthReports();
            return 0;
        } catch (Exception $e) {
            return 1;
        }
    }
}
