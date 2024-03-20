<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Input\InputArgument;
use Datto\Virtualization\EsxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Snapctl command to export log files using network shares.
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class EsxLogsDownloadCommand extends Command
{
    protected static $defaultName = 'virtualization:esx:logs:download';

    /** @var EsxService Services for ESX hosts */
    private $esxService;

    public function __construct(
        EsxService $esxService
    ) {
        parent::__construct();

        $this->esxService = $esxService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Get Esx Logs.')
            ->addArgument('share', InputArgument::REQUIRED, 'Share to save logs in')
            ->addArgument('connection', InputArgument::REQUIRED, 'Connection to list or download logs of')
            ->addArgument(
                'file',
                InputArgument::OPTIONAL,
                'Key of a single log file to download; leave blank to download all files'
            )
            ->addOption('createShare', 'c', InputOption::VALUE_NONE, 'Newly create the share to save the logs to');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shareName = $input->getArgument('share');
        $createNewShare = $input->getOption('createShare');
        $connection = $input->getArgument('connection');
        $file = $input->getArgument('file');

        $filesToDownload = array();
        if (isset($file)) {
            $filesToDownload[] = $file;
        } else {
            $files = $this->esxService->getAllLogFiles($connection);
            foreach ($files as $logFile) {
                $filesToDownload[] = $logFile->getKey();
            }
        }

        $output->writeln('');
        foreach ($filesToDownload as $logFile) {
            $linesDownloaded = $this->esxService->downloadLogFile($connection, $logFile, $shareName, $createNewShare);
            if ($linesDownloaded > 0) {
                $out = sprintf("Downloaded %s log for %s connection (%d lines)\n", $logFile, $connection, $linesDownloaded);
            } else {
                $out = sprintf("Could not find %s log for %s connection\n", $logFile, $connection);
            }
            $output->writeln($out);
        }
        return 0;
    }
}
