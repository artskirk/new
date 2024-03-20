<?php

namespace Datto\App\Console\Command\Restore\EsxUpload;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentService;
use Datto\Feature\FeatureService;
use Datto\Restore\EsxUpload\EsxUploadManager;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Monitor progress of or cancel ESX uploads via the command-line.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class EsxUploadProgressCommand extends AbstractCommand
{
    protected static $defaultName = 'restore:esxupload:progress';

    /** @var EsxUploadManager */
    private $uploadManager;

    /** @var AgentService */
    private $agentService;

    public function __construct(
        EsxUploadManager $esxUploadManager,
        AgentService $agentService
    ) {
        parent::__construct();

        $this->uploadManager = $esxUploadManager;
        $this->agentService = $agentService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_HYPERVISOR_UPLOAD];
    }

    protected function configure()
    {
        $this
            ->addArgument('agent', InputArgument::REQUIRED, 'Agent key name')
            ->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot epoch of the backup')
            ->addOption('cancel', null, InputOption::VALUE_NONE, 'Cancel the running upload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agent = $input->getArgument('agent');
        $snapshot = $input->getArgument('snapshot');

        if (!$this->agentService->exists($agent)) {
            throw new Exception("Agent $agent does not exist");
        }

        if ($input->getOption('cancel')) {
            $output->writeln('Cancelling upload');
            $this->uploadManager->cancel($agent, $snapshot);
        } else {
            $progress = $this->uploadManager->getProgress($agent, $snapshot);
            $output->writeln(var_export($progress, true));
        }
        return 0;
    }
}
