<?php

namespace Datto\Filesystem;

use Datto\Asset\Agent\VolumeMetadata;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\System\MountManager;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * Encapsulates functionality related to repairing a filesystem, especially during the HIR phase.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 */
class FilesystemCheck implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /*
     * Filesystem checker exit codes
     */
    const EXIT_NTFSRESIZE_NO_ERRORS = 0x0;
    const EXIT_NTFSRESIZE_ERRORS = 0x1;
    const EXIT_E2FSCK_UNCORRECTED_ERRORS = 0x4;
    const EXIT_XFS_REPAIR_NO_ERRORS = 0x0;

    /** Timeout ntfsresize check after 3 minutes */
    public const DEFAULT_NTFSRESIZE_TIMEOUT_SEC = 180;
    public const DEFAULT_E2FSCK_TIMEOUT_SEC = 450;
    public const DEFAULT_XFSREPAIR_TIMEOUT_SEC = 450;

    private ProcessFactory $processFactory;
    private Filesystem $filesystem;
    private MountManager $mountManager;
    private DeviceConfig $deviceConfig;
    private NtfsResizeCheckResultDetails $ntfsResizeCheckResultDetails;

    private string $blockDevice;
    private VolumeMetadata $volumeMetadata;

    public function __construct(
        ProcessFactory $processFactory,
        Filesystem $filesystem,
        MountManager $mountManager,
        DeviceConfig $deviceConfig,
        NtfsResizeCheckResultDetails $ntfsResizeCheckResultDetails
    ) {
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
        $this->mountManager = $mountManager;
        $this->deviceConfig = $deviceConfig;
        $this->ntfsResizeCheckResultDetails = $ntfsResizeCheckResultDetails;
    }

    /**
     * Attempt to repair the filesystem on the block device passed into the constructor.
     *
     * The block device will automatically be set to read only mode during the test
     * in order to ensure no data is modified.
     */
    public function execute(string $blockDevice, VolumeMetadata $volumeMetadata): FilesystemCheckResult
    {
        $this->blockDevice = $blockDevice;
        $this->volumeMetadata = $volumeMetadata;

        // This should be extremely rare, but let's not be too sure.
        if (!$this->filesystem->exists($blockDevice)) {
            return $this->createResult(FilesystemCheckResult::RESULT_BLOCK_DEVICE_NOT_FOUND);
        }

        // Probe for the filesystem type
        $filesystemType = $this->mountManager->getFilesystemType($blockDevice);
        if ($filesystemType === null) {
            return $this->createResult(FilesystemCheckResult::RESULT_FS_UNKNOWN);
        }

        // Mark the block device as read only
        if (!$this->setReadOnly($blockDevice)) {
            $this->logger->error('FSC0010 Failed to set block device to read-only');
        }

        // Attempt the repair, depending on the filesystem type
        switch ($filesystemType) {
            case 'ntfs':
                $result = $this->checkNtfsResize();
                break;
            case 'ext2':
            case 'ext3':
            case 'ext4':
                $result = $this->checkFilesystemEXT();
                break;
            case 'xfs':
                $result = $this->checkFilesystemXFS();
                break;
            default:
                $result = $this->createResult(FilesystemCheckResult::RESULT_FS_UNSUPPORTED);
                break;
        }

        // Change the block device back to read write
        if (!$this->setReadOnly($blockDevice, false)) {
            $this->logger->error('FSC0011 Failed to set block device to read/write');
        }

        $this->logger->info(
            'FSC0014 Filesystem integrity check results',
            $result->toArray()
        );

        return $result;
    }

    private function checkNtfsResize(): FilesystemCheckResult
    {
        $timeout = $this->getTimeout('integrityCheckTimeoutNtfsresize', self::DEFAULT_NTFSRESIZE_TIMEOUT_SEC);

        $resultCode = FilesystemCheckResult::RESULT_CHECK_FAILED;
        $resultDetails = [];
        // Run a check specific to ntfs volumes. Ntfsresize does a simple check to
        // determine whether it's possible to mount/resize the volume, which is really all we need for BMRs.
        $process = $this->processFactory
            ->get(['ntfsresize', '--bad-sectors', '--force', '--no-action', '--no-progress-bar', $this->blockDevice])
            ->setTimeout($timeout);

        try {
            $process->run();

            $processOutput = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
            $processExitCode = $process->getExitCode();
            switch ($processExitCode) {
                case static::EXIT_NTFSRESIZE_NO_ERRORS:
                    $resultCode = FilesystemCheckResult::RESULT_FOUND_NO_ERRORS;
                    break;
                case static::EXIT_NTFSRESIZE_ERRORS:
                    $resultCode = FilesystemCheckResult::RESULT_FOUND_ERRORS;
                    $resultDetails = $this->ntfsResizeCheckResultDetails->parseDetails($processOutput);
                    if ($this->ntfsResizeCheckResultDetails->parseDetailsForResultOverrideToPass($processOutput)) {
                        $this->logger->info(
                            'FSC0015 Met conditions to override ntfsresize result to "no_errors_found"',
                            ['details' => $resultDetails, 'rawOutput' => $processOutput]
                        );
                        $resultCode = FilesystemCheckResult::RESULT_FOUND_NO_ERRORS;
                    }
                    break;
            }
        } catch (ProcessTimedOutException $e) {
            $this->logger->error('FSC0019 Timed out running ntfsresize', ['exception' => $e]);
            $resultCode = FilesystemCheckResult::RESULT_CHECK_TIMED_OUT;
        } catch (Throwable $e) {
            $this->logger->error('FSC0017 Failed to run ntfsresize', ['exception' => $e]);
        }

        return $this->createResult($resultCode, $processOutput ?? null, $processExitCode ?? null, $resultDetails);
    }

    private function checkFilesystemEXT(): FilesystemCheckResult
    {
        $timeout = $this->getTimeout('integrityCheckTimeoutE2fsck', self::DEFAULT_E2FSCK_TIMEOUT_SEC);

        $resultCode = FilesystemCheckResult::RESULT_CHECK_FAILED;
        $process = $this->processFactory
            ->get(['e2fsck', '-f', '-n', $this->blockDevice])
            ->setTimeout($timeout);

        try {
            $process->run();

            $processOutput = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
            $processExitCode = $process->getExitCode();
            $errorsFound = $processExitCode === static::EXIT_E2FSCK_UNCORRECTED_ERRORS
                || stristr($processOutput, 'Filesystem still has errors') !== false
                || stristr($processOutput, 'Fix?') !== false;

            if ($errorsFound) {
                $resultCode = FilesystemCheckResult::RESULT_FOUND_ERRORS;
            } else {
                $resultCode = FilesystemCheckResult::RESULT_FOUND_NO_ERRORS;
            }
        } catch (ProcessTimedOutException $e) {
            $this->logger->error('FSC0020 Timed out running e2fsck', ['exception' => $e]);
            $resultCode = FilesystemCheckResult::RESULT_CHECK_TIMED_OUT;
        } catch (Throwable $e) {
            $this->logger->error('FSC0021 Failed to run e2fsck', ['exception' => $e]);
        }

        return $this->createResult($resultCode, $processOutput ?? null, $processExitCode ?? null);
    }

    private function checkFilesystemXFS(): FilesystemCheckResult
    {
        $timeout = $this->getTimeout('integrityCheckTimeoutXfsrepair', self::DEFAULT_XFSREPAIR_TIMEOUT_SEC);

        $resultCode = FilesystemCheckResult::RESULT_CHECK_FAILED;
        $process = $this->processFactory
            ->get(['xfs_repair', '-n', $this->blockDevice])
            ->setTimeout($timeout);

        try {
            $process->run();

            $processOutput = $process->getOutput() . PHP_EOL . $process->getErrorOutput();
            $processExitCode = $process->getExitCode();
            if ($processExitCode !== static::EXIT_XFS_REPAIR_NO_ERRORS) {
                $resultCode = FilesystemCheckResult::RESULT_FOUND_ERRORS;
            } else {
                $resultCode = FilesystemCheckResult::RESULT_FOUND_NO_ERRORS;
            }
        } catch (ProcessTimedOutException $e) {
            $this->logger->error('FSC0022 Timed out running xfs_repair', ['exception' => $e]);
            $resultCode = FilesystemCheckResult::RESULT_CHECK_TIMED_OUT;
        } catch (Throwable $e) {
            $this->logger->error('FSC0023 Failed to run xfs_repair', ['exception' => $e]);
        }

        return $this->createResult($resultCode, $processOutput ?? null, $processExitCode ?? null);
    }

    /**
     * Set the block device to read only
     * or read/write (if false was given for the second parameter).
     *
     * @param string $blockDevice
     * @param bool $readonly
     * @return bool
     */
    private function setReadOnly(string $blockDevice, bool $readonly = true): bool
    {
        $process = $this->processFactory
            ->get(['blockdev', $readonly === true ? '--setro' : '--setrw', $blockDevice])
            ->setTimeout(5);
        $process->run();
        return $process->isSuccessful();
    }

    private function createResult(
        string $resultCode,
        string $processOutput = null,
        int $processExitCode = null,
        array $resultDetails = null
    ): FilesystemCheckResult {
        return new FilesystemCheckResult(
            $resultCode,
            $this->volumeMetadata,
            $resultDetails,
            $processOutput,
            $processExitCode
        );
    }

    /**
     * Get the timeout value from deviceConfig if it has been overridden, otherwise return the default timeout
     *
     * @param string $timeoutKey The deviceConfig key that contains a timeout value
     * @param int $defaultTimeout Value to use if the $timeoutKey does not exist or is too quick
     * @return int Timeout value to use
     */
    private function getTimeout(string $timeoutKey, int $defaultTimeout): int
    {
        $timeout = (int)$this->deviceConfig->getRaw($timeoutKey);

        // Treat poorly formatted files and 0 or negative timeouts as invalid and assign the default timeout instead
        if ($timeout < 1) {
            return $defaultTimeout;
        }

        $this->logger->info('FSC0024 Using non-default timeout for filesystem integrity check', ['timeoutKey' => $timeoutKey, 'timeout' => $timeout, 'defaultTimeout' => $defaultTimeout]);

        return $timeout;
    }
}
