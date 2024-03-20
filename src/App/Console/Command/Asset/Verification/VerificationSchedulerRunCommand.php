<?php

namespace Datto\App\Console\Command\Asset\Verification;

use Datto\App\Console\Command\CommandValidator;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetService;
use Datto\Feature\FeatureService;
use Datto\Verification\VerificationScheduler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SchedulerRunCommand
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class VerificationSchedulerRunCommand extends AbstractVerificationCommand
{
    protected static $defaultName = 'asset:verification:scheduler:run';

    /** @var VerificationScheduler */
    private $verificationScheduler;

    /** @var FeatureService */
    private $featureService;

    /** @var AgentService */
    private $agentService;

    public function __construct(
        VerificationScheduler $verificationScheduler,
        FeatureService $featureService,
        AgentService $agentService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->verificationScheduler = $verificationScheduler;
        $this->featureService = $featureService;
        $this->agentService = $agentService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Run verification schedule')
            ->addArgument('asset', InputArgument::OPTIONAL, 'Name of the asset.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Run for all assets.');
    }

    /**
     * @inheritdoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->featureService->assertSupported(FeatureService::FEATURE_VERIFICATIONS);

        if ($input->getArgument('asset') && $input->getOption('all')) {
            throw new \InvalidArgumentException("Cannot use --all with asset.");
        } elseif ($input->getOption('all')) {
            $this->runAllAssets();
        } elseif ($input->getArgument('asset')) {
            $assetKeyName = $input->getArgument('asset');
            $this->runSingleAsset($assetKeyName);
        } else {
            throw new \InvalidArgumentException("Asset or all assets must be specified.");
        }
        return 0;
    }

    /**
     * @param string $assetKeyName
     */
    private function runSingleAsset(string $assetKeyName): void
    {
        $agent = $this->agentService->get($assetKeyName);
        $this->verificationScheduler->scheduleVerifications($agent);
    }

    private function runAllAssets(): void
    {
        $this->verificationScheduler->scheduleVerificationsForAllAgents();
    }
}
