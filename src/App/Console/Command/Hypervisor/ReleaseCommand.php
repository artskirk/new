<?php

namespace Datto\App\Console\Command\Hypervisor;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Verification\CloudAssistedVerificationOffloadService;
use Datto\Asset\Agent\AgentService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;

/**
 * Release hypervisor pairing records.
 *
 * @author Jozef Batko <jozef.batko@datto.com>
 *
 */
class ReleaseCommand extends AbstractCommand
{
    protected static $defaultName = 'hypervisor:release';
    private AgentService $agentService;
    private CloudAssistedVerificationOffloadService $cloudAssistedVerificationOffloadService;

    public function __construct(CloudAssistedVerificationOffloadService $cloudAssistedVerificationOffloadService, AgentService $agentService)
    {
        parent::__construct();
        $this->agentService = $agentService;
        $this->cloudAssistedVerificationOffloadService = $cloudAssistedVerificationOffloadService;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Release hypervisor pairing records (not applicable to SIRIS on-prem)')
            ->setHelp('This command releases hypervisor pairing records (not applicable to SIRIS on-prem)')
            ->addOption('assetKey', null, InputOption::VALUE_REQUIRED, 'Mandatory Asset Key');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $assetKey = $input->getOption('assetKey');
        if (!$assetKey) {
            $output->writeln('Asset key is required.');
            return Command::FAILURE;
        }

        $asset = $this->agentService->get($assetKey);

        try {
            $this->cloudAssistedVerificationOffloadService->releaseConnection($asset);
        } catch (\Exception $e) {
            $output->writeln('Attempt to release hypervisor connection failed.');
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    /**
     * @inheritdoc
     */
    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_HYPERVISOR_CONNECTIONS
        ];
    }
}
