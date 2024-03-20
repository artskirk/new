<?php

namespace Datto\App\Console\Command\Share\Local;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Retention;
use Datto\Asset\Share\Share;
use Datto\App\Console\Command\AbstractShareCommand;
use Datto\Asset\Share\ShareService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareLocalRetentionSetCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:local:retention:set';


    /** @var FeatureService */
    private $featureService;

    public function __construct(
        FeatureService $featureService,
        CommandValidator $commandValidator,
        ShareService $shareService
    ) {
        parent::__construct($commandValidator, $shareService);

        $this->featureService = $featureService;
    }
    
    protected function configure()
    {
        $this
            ->setDescription("Set local retention duration for a share's snapshots")
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Set local retention for a share')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set local retention for all current shares')
            ->addArgument('daily', InputArgument::REQUIRED, "Daily retention hours")
            ->addArgument('weekly', InputArgument::REQUIRED, "Weekly retention hours")
            ->addArgument('monthly', InputArgument::REQUIRED, "Monthly retention hours")
            ->addArgument('maximum', InputArgument::REQUIRED, "Delete local hours")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_CONFIGURABLE_LOCAL_RETENTION)) {
            throw new \Exception("Configurable Local Retention is not available");
        }

        $this->validateArgs($input);

        $shares = $this->getShares($input);

        $daily = $input->getArgument('daily');
        $weekly = $input->getArgument('weekly');
        $monthly = $input->getArgument('monthly');
        $maximum = $input->getArgument('maximum');

        /** @var Share $share */
        foreach ($shares as $share) {
            if (!$share->getOriginDevice()->isReplicated()) {
                $retention = new Retention($daily, $weekly, $monthly, $maximum);
                $share->getLocal()->setRetention($retention);
                $this->shareService->save($share);
            }
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);

        $daily = $input->getArgument('daily');
        $weekly = $input->getArgument('weekly');
        $monthly = $input->getArgument('monthly');
        $maximum = $input->getArgument('maximum');

        $allowedValuesDaily = array(24, 48, 72, 96, 120, 144, 168, 336, 504, 744, Retention::NEVER_DELETE);
        $allowedValuesMaximum = array(24, 168, 336, 744, 1488, 2232, 2976, 4464, 6696, 8760, 17520, 26280, 35064,
            43830, 52596, 61362, Retention::NEVER_DELETE);

        $this->commandValidator->validateValue(
            $daily,
            new Assert\Choice($allowedValuesDaily),
            'Daily retention must be one of these values: '.implode(', ', $allowedValuesDaily)
        );
        $this->commandValidator->validateValue(
            $weekly,
            new Assert\Range(array('min' => max(array(168, $daily + 1)), 'max' => Retention::NEVER_DELETE)),
            "Weekly retention must be greater than or equal to 168, greater than daily retention ($daily), "  .
                "and less than or equal to " . Retention::NEVER_DELETE
        );
        $this->commandValidator->validateValue(
            $monthly,
            new Assert\Range(array('min' => max(array(731, $weekly + 1)), 'max' => Retention::NEVER_DELETE)),
            "Monthly retention must be greater than or equal to 731, greater than weekly retention ($weekly), ".
                "and less than or equal to " . Retention::NEVER_DELETE
        );
        $this->commandValidator->validateValue(
            $maximum,
            new Assert\Choice($allowedValuesMaximum),
            'Maximum retention must be one of these values: '.implode(', ', $allowedValuesMaximum)
        );
    }
}
