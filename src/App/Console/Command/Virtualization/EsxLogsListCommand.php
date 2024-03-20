<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Input\InputArgument;
use Datto\Virtualization\EsxLogFile;
use Datto\Virtualization\EsxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Snapctl command to list available ESX logs
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class EsxLogsListCommand extends Command
{
    protected static $defaultName = 'virtualization:esx:logs:list';

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
            ->setDescription('List Esx Logs.')
            ->addArgument('connection', InputArgument::REQUIRED, 'Connection to list logs from');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $input->getArgument('connection');
        $logFiles = $this->esxService->getAllLogFiles($connection);
        $out = $this->listLogs($logFiles);
        $output->writeln($out);
        return 0;
    }

    /**
     * Formats the given log files in a list format.
     *
     * @param EsxLogFile[] $logFiles Array of objects specifying ESX log files
     * @return string Formatted output
     */
    private function listLogs(array $logFiles)
    {
        $out = sprintf("\n\n%35s%50s\n", 'log', 'key');
        $out = $out . sprintf("%85s\n", '-------------------------------------------------------------------------------------');

        foreach ($logFiles as $log) {
            $out = $out . sprintf("%35s%50s\n", $log->getPath(), $log->getKey());
        }

        return $out;
    }
}
