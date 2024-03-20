<?php
namespace Datto\Filesystem;

use Datto\Asset\Agent\VolumeMetadata;

/**
 * Encapsulates details regarding the result of a filesystem check.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 */
class FilesystemCheckResult
{
    /*
     * All possible result codes of a filesystem check
     */
    const RESULT_FOUND_NO_ERRORS = 'no_errors_found';
    const RESULT_FOUND_MINOR_ERRORS = 'minor_errors_found';
    const RESULT_FOUND_ERRORS = 'errors_found';
    const RESULT_FS_UNKNOWN = 'unknown_filesystem';
    const RESULT_FS_UNSUPPORTED = 'unsupported_filesystem';
    const RESULT_BLOCK_DEVICE_NOT_FOUND = 'block_device_not_found';
    const RESULT_SET_READ_ONLY_FAILED = 'set_read_only_failed';
    const RESULT_SET_READ_WRITE_FAILED = 'set_read_write_failed';
    const RESULT_CHECK_FAILED = 'check_failed';
    const RESULT_CHECK_TIMED_OUT = 'check_failed_timeout';
    const RESULT_UNKNOWN_ISSUE = 'unknown_issue';

    /** @var string */
    private $resultCode;

    /** @var VolumeMetadata */
    private $volumeMetadata;

    /** @var string|null */
    private $processOutput;

    /** @var int|null */
    private $processExitCode;

    /** @var array|null */
    private $resultDetails;

    public function __construct(
        string $resultCode,
        VolumeMetadata $volumeMetadata,
        array $resultDetails = null,
        string $processOutput = null,
        int $processExitCode = null
    ) {
        $this->resultCode = $resultCode;
        $this->volumeMetadata = $volumeMetadata;
        $this->resultDetails = $resultDetails;
        $this->processOutput = $processOutput;
        $this->processExitCode = $processExitCode;
    }

    /**
     * Returns the result of the check.
     * Should be one of the static::RESULT_* constants.
     *
     * @return string
     */
    public function getResultCode(): string
    {
        return $this->resultCode;
    }

    /**
     * Returns the volume metadata for the volume that the filesystem check was run against
     *
     * @return VolumeMetadata
     */
    public function getVolumeMetadata(): VolumeMetadata
    {
        return $this->volumeMetadata;
    }

    /**
     * Returns output from the process executed during the check.
     * In cases where no process was run, null will be returned.
     *
     * @return string|null
     */
    public function getProcessOutput()
    {
        return $this->processOutput;
    }

    /**
     * Returns exit code from the process executed during the check.
     * In cases where no process was run, null will be returned.
     *
     * @return int|null
     */
    public function getProcessExitCode()
    {
        return $this->processExitCode;
    }

    /**
     * @return array|null
     */
    public function getResultDetails()
    {
        return $this->resultDetails;
    }

    /**
     * Get an array representation of this object
     *
     * @return array
     */
    public function toArray()
    {
        $array = [];
        $array['result_code'] = $this->resultCode;
        $array['result_desc'] = $this->getResultCodeDescription();
        if ($this->processOutput !== null || $this->processExitCode !== null) {
            $array['process'] = [];
            if ($this->processOutput !== null) {
                $array['process']['output'] = $this->processOutput;
            }
            if ($this->processExitCode !== null) {
                $array['process']['exit_code'] = $this->processExitCode;
            }
        }

        if ($this->resultDetails !== null) {
            $array['resultDetails'] = $this->resultDetails;
        }

        if ($this->volumeMetadata !== null) {
            $array['volumeMetadata'] = $this->volumeMetadata->toArray();
        }

        return $array;
    }

    /**
     * Returns a human-friendly description of the check result.
     *
     * @return string
     */
    public function getResultCodeDescription()
    {
        switch ($this->resultCode) {
            case static::RESULT_FOUND_NO_ERRORS:
                return 'No filesystem errors were detected';
            case static::RESULT_FOUND_MINOR_ERRORS:
                return 'Detected minor filesystem errors';
            case static::RESULT_FOUND_ERRORS:
                return 'Detected filesystem errors';
            case static::RESULT_FS_UNKNOWN:
                return 'Failed to detect filesystem type';
            case static::RESULT_FS_UNSUPPORTED:
                return 'Checking is currently unsupported for this filesystem type';
            case static::RESULT_CHECK_FAILED:
                return 'An unexpected error occurred while checking the filesystem';
            case static::RESULT_CHECK_TIMED_OUT:
                return 'Timed out while checking the filesystem';
            case static::RESULT_BLOCK_DEVICE_NOT_FOUND:
                return 'An error occurred while preparing the volume for checking';
            case static::RESULT_SET_READ_ONLY_FAILED:
                return 'Failed to set block device to read-only';
            case static::RESULT_SET_READ_WRITE_FAILED:
                return 'Failed to set block device to read/write';
            default:
                return 'Unknown result code';
        }
    }
}
