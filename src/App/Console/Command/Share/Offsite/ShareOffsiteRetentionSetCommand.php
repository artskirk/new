<?php
namespace Datto\App\Console\Command\Share\Offsite;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Offsite\OffsiteSettingsService;
use Datto\Asset\Retention;
use Datto\Asset\Share\ShareService;
use Datto\Billing;
use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class ShareOffsiteRetentionSetCommand
 */
class ShareOffsiteRetentionSetCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:offsite:retention:set';

    /** @var Billing\Service */
    private $billingService;

    /** @var OffsiteSettingsService */
    private $offsiteSettingsService;

    public function __construct(
        Billing\Service $billingService,
        OffsiteSettingsService $offsiteSettingsService,
        CommandValidator $commandValidator,
        ShareService $shareService
    ) {
        parent::__construct($commandValidator, $shareService);

        $this->billingService = $billingService;
        $this->offsiteSettingsService = $offsiteSettingsService;
    }

    protected function configure()
    {
        $this
            ->setDescription("Set offsite retention duration for a share's snapshots")
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to set retention for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set retention for all shares.')
            ->addArgument('daily', InputArgument::REQUIRED, 'Time to retain daily offsites, in hours.')
            ->addArgument('weekly', InputArgument::REQUIRED, 'Time to retain weekly offsites, in hours.')
            ->addArgument('monthly', InputArgument::REQUIRED, 'Time to retain monthly offsites, in hours.')
            ->addArgument('maximum', InputArgument::REQUIRED, 'Maximum time to keep an offsite, in hours.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);

        $daily = $input->getArgument('daily');
        $weekly = $input->getArgument('weekly');
        $monthly = $input->getArgument('monthly');
        $maximum = $input->getArgument('maximum');

        foreach ($shares as $share) {
            if ($share->getOriginDevice()->isReplicated()) {
                continue;
            }
            $this->offsiteSettingsService->setRetention(
                $share->getKeyName(),
                $daily,
                $weekly,
                $monthly,
                $maximum
            );
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
        $allowedValuesMaximum = array(168, 336, 744, 1488, 2232, 2976, 4464, 6696, 8760, 17520, 26280, 35064,
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
