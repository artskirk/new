<?php

namespace Datto\App\Console\Command\Asset\Verification\Hardware;

use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Asset\Agent\AgentService;
use Datto\Feature\FeatureService;
use Datto\Verification\VerificationService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Override VM hardware specs used at verification time.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class OverrideCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'asset:verification:hardware:override';

    /** @var VerificationService */
    private $verificationService;

    /** @var FeatureService */
    private $featureService;

    public function __construct(
        VerificationService $verificationService,
        FeatureService $featureService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->verificationService = $verificationService;
        $this->featureService = $featureService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Override CPU cores and RAM amount for screenshot verifications')
            ->addArgument('agent', InputArgument::OPTIONAL, 'Target agent')
            ->addOption('cpus', null, InputOption::VALUE_REQUIRED, 'Number of CPU cores, must be less than total number of physical cores (leave blank to clear)')
            ->addOption('ram', null, InputOption::VALUE_REQUIRED, 'Amount of RAM in MiB, must be less than total amount of physical ram (leave blank to clear)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Target all agents');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->featureService->assertSupported(FeatureService::FEATURE_VERIFICATIONS);

        $overrideCpuCores = $input->getOption('cpus');
        $overrideRamInMiB = $input->getOption('ram');
        $clear = false;

        if ($overrideCpuCores === null && $overrideRamInMiB === null) {
            $clear = true;
        } elseif ($overrideCpuCores === null || $overrideRamInMiB === null) {
            throw new \Exception('"cpus" and "ram" must either both be provided, or neither.');
        }

        $agents = $this->getAgents($input);
        foreach ($agents as $agent) {
            if ($clear) {
                $output->writeln("Clearing screenshotOverride for {$agent->getKeyName()} ...");
                $this->verificationService->clearScreenshotOverride($agent->getKeyName());
            } else {
                $output->writeln("Setting screenshotOverride for {$agent->getKeyName()} ...");
                $this->verificationService->setScreenshotOverride($agent->getKeyName(), $overrideCpuCores, $overrideRamInMiB);
            }
        }
        return 0;
    }
}
