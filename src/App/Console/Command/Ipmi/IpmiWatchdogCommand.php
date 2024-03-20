<?php

namespace Datto\App\Console\Command\Ipmi;

use Datto\Feature\FeatureService;
use Datto\System\WatchdogService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * IPMI watchdog command
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class IpmiWatchdogCommand extends Command
{
    protected static $defaultName = 'ipmi:watchdog';

    /** @var FeatureService */
    private $featureService;

    /** @var WatchdogService */
    private $watchdogService;

    public function __construct(
        FeatureService $featureService,
        WatchdogService $watchdogService
    ) {
        parent::__construct();

        $this->featureService = $featureService;
        $this->watchdogService = $watchdogService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Enables/Disables IPMI Watchdog');

        $this->addOption('enable', null, InputOption::VALUE_NONE, 'Enable IPMI watchdog');
        $this->addOption('disable', null, InputOption::VALUE_NONE, 'Disable IPMI watchdog');
        $this->addOption('status', null, InputOption::VALUE_NONE, 'Checks if IPMI watchog is active');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_IPMI)) {
            $output->writeln('The IPMI feature is unavailable on this host.');
            return 1;
        }

        if ($input->getOption('status')) {
            if ($this->watchdogService->isEnabled()) {
                $output->writeln('enabled');
            } else {
                $output->writeln('disabled');
            }
            return 0;
        }

        if ($input->getOption('enable')) {
            $output->writeln('Enabling watchdog service...');
            $this->watchdogService->enable();
        } elseif ($input->getOption('disable')) {
            $output->writeln('Disabling watchdog service...');
            $this->watchdogService->disable();
        }
        return 0;
    }
}
