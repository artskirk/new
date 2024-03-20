<?php

namespace Datto\App\Console\Command\Agentless\Proxy;

use Datto\Agentless\Proxy\AgentlessBackupService;
use Datto\Agentless\Proxy\AgentlessSessionId;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Start agentless backup via proxy
 *
 * @author Mario Rial <mrial@datto.com>
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class StartBackupCommand extends Command
{
    protected static $defaultName = 'agentless:proxy:start:backup';

    /** @var AgentlessBackupService */
    private $agentlessBackupService;

    public function __construct(
        AgentlessBackupService $agentlessBackupService
    ) {
        parent::__construct();

        $this->agentlessBackupService = $agentlessBackupService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Starts agent-less backup')
            ->addOption(
                'agentless-session',
                null,
                InputOption::VALUE_REQUIRED,
                'Agentless session id returned by initialize'
            )
            ->addOption(
                'volume',
                null,
                InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED,
                'Volume to backup'
            )
            ->addOption(
                'changeId-file',
                null,
                InputOption::VALUE_IS_ARRAY|InputOption::VALUE_OPTIONAL,
                'ChangeId file of the volume'
            )
            ->addOption(
                'destination-file',
                null,
                InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED,
                'Destination file'
            )
            ->addOption('full', null, InputOption::VALUE_NONE, 'force full backup')
            ->addOption('diff', null, InputOption::VALUE_NONE, 'force diff merge')
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Do dry run')
            ->addOption(
                'jobId',
                null,
                InputOption::VALUE_REQUIRED,
                'Required to write status to shm'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $volumeGuids = $input->getOption('volume');
        $changeIdFiles = $input->getOption('changeId-file');
        $destinationFiles = $input->getOption('destination-file');

        $forceFull = $input->getOption('full');
        $forceDiff = $input->getOption('diff');
        $dryRun = $input->getOption('dryRun');
        $jobId = $input->getOption('jobId');
        $agentlessSessionId = $input->getOption('agentless-session');
        $runInBackground = $input->getOption('background');

        $output->writeln([
            '================================================',
            'Starting agentless backup with parameters...',
            '= Agentless SessionId:' . $agentlessSessionId,
            '= Backup JobId:' . $jobId,
            '= Volumes:' . json_encode($volumeGuids),
            '= Change Ids:' . json_encode($changeIdFiles),
            '= Destinations:' . json_encode($destinationFiles),
            '= Force Full: ' . ($forceFull ? 'true' : 'false'),
            '= Force Diff: ' . ($forceDiff ? 'true' : 'false'),
            '= Do Dry-Run: ' . ($dryRun ? 'true' : 'false'),
            '= Background: ' . ($runInBackground ? 'true' : 'false'),
            '================================================='
        ]);

        $sessionId = AgentlessSessionId::fromString($agentlessSessionId);

        if ($runInBackground) {
            $jobId = $this->agentlessBackupService->takeBackupBackground(
                $sessionId,
                $volumeGuids,
                $destinationFiles,
                $changeIdFiles,
                $forceDiff,
                $forceFull
            );
            $output->writeln("Backup started in the background: $jobId");
        } else {
            $this->agentlessBackupService->takeBackup(
                $sessionId,
                $volumeGuids,
                $destinationFiles,
                $changeIdFiles,
                $jobId,
                $forceDiff,
                $forceFull
            );
        }
        return 0;
    }
}
