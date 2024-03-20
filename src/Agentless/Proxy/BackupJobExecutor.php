<?php

namespace Datto\Agentless\Proxy;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\DeviceLoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Executes backup jobs.
 *
 * @author Mario Rial <mrial@datto.com>
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class BackupJobExecutor
{
    /** @var int
     *  128 KiB seems to be the best buffer to use after trying a lot of them.
     *  Ref: https://eklitzke.org/efficient-file-copying-on-linux
     */
    public const MERCURYFTP_BUFFER_SIZE_BYTE = 128 * 1024;
    public const MERCURYFTP_BINARY_PATH = '/usr/bin/mercuryftp';

    public const HYPER_SHUTTLE_BINARY_PATH = '/usr/bin/hyper-shuttle';
    public const HYPER_SHUTTLE_LIBRARY_PATH = '/usr/lib/x86_64-linux-gnu/vmware-vix-disklib/6.7/lib64';

    private ChangeIdService $changeIdService;
    private ProcessFactory $processFactory;

    public function __construct(
        ChangeIdService $changeIdService,
        ProcessFactory $processFactory
    ) {
        $this->changeIdService = $changeIdService;
        $this->processFactory = $processFactory;
    }

    public function executeBackupJob(
        BackupJob $backupJob,
        AgentlessSession $agentlessSession,
        DeviceLoggerInterface $logger,
        callable $progressCallback = null
    ) {
        if (count($backupJob->getChangedAreas()) === 0) {
            $logger->info('PBJ0000 No Changed Areas detected, execution not needed, skipping...');
            return;
        }

        $logger->info("PBJ0001 Backing up VM snapshot.", ['transferMethod' => $agentlessSession->getTransferMethod()]);
        try {
            if ($agentlessSession->isUsingHyperShuttle()) {
                $this->copyUsingHyperShuttle(
                    $backupJob,
                    $agentlessSession,
                    $logger,
                    $progressCallback
                );
            } else {
                $this->copyUsingMercuryFtp(
                    $backupJob,
                    $logger,
                    $progressCallback
                );
            }
        } catch (\Throwable $t) {
            $logger->error("PBJ0002 Error while executing backup job", [
                "exception" => $t,
                "transferMethod" => $agentlessSession->getTransferMethod()
            ]);
            throw $t;
        }

        $changeIdFile = $backupJob->getChangeIdFile();
        $oldChangeId = $backupJob->getChangeId();
        $newChangeId = $backupJob->getNewChangeId();

        $logger->info("PBJ0003 Partition Backup Job executed successfully.");
        $logger->info('PBJ0004 Updating changeId', ['changeIdFile' => $changeIdFile, 'oldChangeId' => $oldChangeId, 'newChangeId' => $newChangeId]);

        $this->changeIdService->writeChangeId($changeIdFile, $newChangeId);
        $logger->info('PBJ0005 ChangeId updated.');
    }

    private function runBackupProcess(
        Process $process,
        DeviceLoggerInterface $logger,
        callable $progressCallback = null
    ): int {
        $logger->info('PBJ0007 Executing backup process', ['process' => $process->getCommandLine()]);
        $process->setTimeout(null);
        $process->start();

        return $process->wait(function ($type, $buffer) use ($progressCallback, $logger) {
            if ($type === Process::OUT) {
                $progressInfo = json_decode($buffer, true);

                if ($progressInfo && isset($progressInfo['processed_bytes'])) {
                    $totalBytes = $progressInfo['processed_bytes'];
                    $elapsedTimeMs = $progressInfo['elapsed_time_ms'];
                    $bytesPerSecond = $progressInfo['bytes_per_second'];
                    $writtenBytes = $progressInfo['written_bytes'] ?? 0;
                    $skippedBytes = $progressInfo['skipped_bytes'] ?? 0;

                    if ($progressCallback) {
                        call_user_func(
                            $progressCallback,
                            $totalBytes,
                            $writtenBytes,
                            $skippedBytes,
                            $elapsedTimeMs,
                            $bytesPerSecond
                        );
                    }
                }
            } else {
                $logger->info('PBJ0010 Backup process wrote unexpected output', ['buffer' => $buffer]);
            }
        });
    }

    private function copyUsingMercuryFtp(
        BackupJob $job,
        DeviceLoggerInterface $logger,
        callable $progressCallback = null
    ): void {
        $input = json_encode([
            'extents' => $job->getChangedAreas()
        ], JSON_PRETTY_PRINT);

        $command = [
            self::MERCURYFTP_BINARY_PATH,
            '-v', '2',
            '-b', self::MERCURYFTP_BUFFER_SIZE_BYTE,
            '-m' //Transfer type mapped incremental through standard input.
        ];

        if ($job->isDiffMerge()) {
            $logger->info("PBJ0006 Calling mercuryftp with diffmerge option");
            $command[] = '-d';
        }

        $command[] = $job->getSource();
        $command[] = $job->getDestination();
        $process = $this->processFactory->get($command, null, null, $input);
        $exitCode = $this->runBackupProcess($process, $logger, $progressCallback);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Error calling mercuryftp: ' . $exitCode);
        }
    }

    private function copyUsingHyperShuttle(
        BackupJob $job,
        AgentlessSession $session,
        DeviceLoggerInterface $logger,
        callable $progressCallback = null
    ): void {
        $config = json_encode([
            'server_name' => $session->getHost(),
            'user_name' => $session->getUser(),
            'password' => $session->getPassword(),
            'vm_id' => $session->getVmMoRefId(),
            'snapshot_id' => $session->getSnapshotMoRefId(),
            'disk_path' => $job->getSource(),
            'output_path' => $job->getDestination(),
            'extents' => $job->getChangedAreas(),
            'lib_path' => self::HYPER_SHUTTLE_LIBRARY_PATH,
            'diff_merge' => $job->isDiffMerge(),
            'async' => true,
            'force_nbd' => $session->isForceNbd()
         ]);

        $env = [
            "LD_LIBRARY_PATH" => self::HYPER_SHUTTLE_LIBRARY_PATH
        ];

        $process = $this->processFactory->get([self::HYPER_SHUTTLE_BINARY_PATH], null, $env, $config);
        $exitCode = $this->runBackupProcess($process, $logger, $progressCallback);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Error calling hyper-shuttle: ' . $exitCode);
        }
    }
}
