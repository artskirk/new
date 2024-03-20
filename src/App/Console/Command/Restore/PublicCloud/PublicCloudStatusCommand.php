<?php

namespace Datto\App\Console\Command\Restore\PublicCloud;

use Datto\App\Console\Command\CommandValidator;
use Datto\Service\Restore\Export\PublicCloud\PublicCloudManager;
use Datto\Service\Restore\Export\PublicCloud\PublicCloudRestore;
use Datto\Service\Restore\Export\PublicCloud\PublicCloudRestoreStatus;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print status of a running public cloud upload.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudStatusCommand extends BasePublicCloudCommand
{
    protected static $defaultName = 'restore:public:status';

    /** @var PublicCloudManager */
    private $publicCloudManager;

    public function __construct(
        CommandValidator $commandValidator,
        PublicCloudManager $publicCloudManager
    ) {
        $this->publicCloudManager = $publicCloudManager;
        parent::__construct($commandValidator);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        parent::configure();

        $this->setDescription('Print the status of an upload to the public cloud.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $agentKey = $input->getArgument('agent');
        $snapshot = $input->getArgument('snapshot');

        $status = $this->publicCloudManager->getStatus(
            $agentKey,
            $snapshot
        );

        $this->writeTableOutput(
            $output,
            $status
        );

        return 0;
    }

    private function writeTableOutput(OutputInterface $output, PublicCloudRestoreStatus $status): void
    {
        $percentComplete = '';
        if ($status->getState() === PublicCloudRestore::STATE_UPLOADING) {
            $percentComplete = $status->getTotalPercentComplete();
        }

        $azCopyState = $status->getAzCopyStatus();
        if (!is_null($azCopyState)) {
            $totalToUpload = $azCopyState->getTotalSize();
            $totalUploaded = $azCopyState->getTotalUploaded();
            $currentFile = $azCopyState->getFilePath();
            $serverBusyPercentage = $azCopyState->getServerBusyPercentage();
            $networkErrorPercentage = $azCopyState->getNetworkErrorPercentage();
            $pid = $azCopyState->getAzCopyPid();
            $jid = $azCopyState->getJobId();
        }

        $table = new Table($output);
        $table->setHeaders(
            [
                'Status',
                'Percent Complete',
                'Bytes Uploaded',
                'Total Bytes',
                'File',
                'Server Busy Percentage',
                'Network Error Percentage',
                'PID',
                'JID'
            ]
        );
        $table->setRows(
            [
                [
                    $status->getState(),
                    $percentComplete ?? '',
                    $totalUploaded ?? '',
                    $totalToUpload ?? '',
                    $currentFile ?? '',
                    $serverBusyPercentage ?? '',
                    $networkErrorPercentage ?? '',
                    $pid ?? '',
                    $jid ?? ''
                ]
            ]
        );
        $table->render();
    }
}
