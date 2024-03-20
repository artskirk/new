<?php

namespace Datto\App\Console\Command\Restore\EsxUpload;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Feature\FeatureService;
use Datto\Restore\EsxUpload\EsxUploadManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run an esx upload for an agent
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class EsxUploadRunCommand extends AbstractCommand
{
    protected static $defaultName = 'restore:esxupload:run';

    /** @var EsxUploadManager */
    private $uploadManager;

    /** @var AgentService */
    private $agentService;

    /** @var TempAccessService */
    private $tempAccessService;

    /** @var EncryptionService */
    private $encryptionService;

    public function __construct(
        EsxUploadManager $esxUploadManager,
        AgentService $agentService,
        TempAccessService $tempAccessService,
        EncryptionService $encryptionService
    ) {
        parent::__construct();

        $this->uploadManager = $esxUploadManager;
        $this->agentService = $agentService;
        $this->tempAccessService = $tempAccessService;
        $this->encryptionService = $encryptionService;
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
            ->addArgument('connectionName', InputArgument::REQUIRED, 'Name of the esx connection')
            ->addArgument('datastore', InputArgument::REQUIRED, 'Esx datastore name')
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory within the datastore of where to upload the files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agent = $this->agentService->get($input->getArgument('agent'));
        $this->unsealAgentIfRequired($agent, $this->encryptionService, $this->tempAccessService, $input, $output, true);

        $this->uploadManager->doUpload(
            $input->getArgument('agent'),
            $input->getArgument('snapshot'),
            $input->getArgument('connectionName'),
            $input->getArgument('datastore'),
            $input->getArgument('directory')
        );
        return 0;
    }
}
