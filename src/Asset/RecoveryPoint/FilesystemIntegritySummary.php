<?php

namespace Datto\Asset\RecoveryPoint;

use Datto\Asset\Agent\Volume;
use Datto\Asset\Agent\VolumeMetadata;
use Datto\Filesystem\FilesystemCheckResult;
use Datto\Filesystem\NtfsResizeCheckResultDetails;
use ReflectionClass;

/**
 * Summary of filesystem integrity check results.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class FilesystemIntegritySummary
{
    private const FILESYSTEM_TYPE_NTFS = 'ntfs';

    private const WARNING_FILESYSTEM_CODES = [
        FilesystemCheckResult::RESULT_FOUND_MINOR_ERRORS
    ];

    private const ERROR_FILESYSTEM_CODES = [
        FilesystemCheckResult::RESULT_FOUND_ERRORS
    ];

    private const CRITICAL_FILESYSTEM_CODES = [
        FilesystemCheckResult::RESULT_FS_UNKNOWN,
        FilesystemCheckResult::RESULT_BLOCK_DEVICE_NOT_FOUND,
        FilesystemCheckResult::RESULT_SET_READ_ONLY_FAILED,
        FilesystemCheckResult::RESULT_SET_READ_WRITE_FAILED
    ];

    private const PROCESS_FAILED_FILESYSTEM_CODES = [
        FilesystemCheckResult::RESULT_CHECK_FAILED
    ];

    private const PROCESS_TIMEOUT_FILESYSTEM_CODES = [
        FilesystemCheckResult::RESULT_CHECK_TIMED_OUT
    ];

    /** @var VolumeMetadata[] */
    private array $healthy;

    /** @var VolumeMetadata[] */
    private array $warning;

    /** @var VolumeMetadata[] */
    private array $error;

    /** @var VolumeMetadata[] */
    private array $critical;

    /** @var VolumeMetadata[] */
    private array $processFailed;

    /** @var VolumeMetadata[] */
    private array $processTimeout;

    /** @var array */
    private array $ntfsErrors;

    private function __construct(
        array $healthy,
        array $warning,
        array $error,
        array $critical,
        array $processFailed,
        array $processTimeout,
        array $ntfsErrors
    ) {
        $this->healthy = $healthy;
        $this->warning = $warning;
        $this->error = $error;
        $this->critical = $critical;
        $this->processFailed = $processFailed;
        $this->processTimeout = $processTimeout;
        $this->ntfsErrors = $ntfsErrors;
    }

    /**
     * Get volumes with healthy filesystems.
     *
     * @return VolumeMetadata[]
     */
    public function getHealthy(): array
    {
        return $this->healthy;
    }

    /**
     * Get volumes with minor filesystem issues.
     *
     * @return VolumeMetadata[]
     */
    public function getWarning(): array
    {
        return $this->warning;
    }

    /**
     * Get volumes with filesystem errors.
     *
     * @return VolumeMetadata[]
     */
    public function getError(): array
    {
        return $this->error;
    }

    /**
     * Get volumes with critical filesystem errors (eg. the filesystem could not be detected).
     *
     * @return VolumeMetadata[]
     */
    public function getCritical(): array
    {
        return $this->critical;
    }

    /**
     * Get volumes that failed to run the filesystem integrity check.
     *
     * @return VolumeMetadata[]
     */
    public function getProcessFailed(): array
    {
        return $this->processFailed;
    }

    /**
     * Get volumes that timed out running the filesystem integrity check.
     *
     * @return VolumeMetadata[]
     */
    public function getProcessTimeout(): array
    {
        return $this->processTimeout;
    }

    /**
     * Check if all volumes are healthy.
     */
    public function hasAllHealthy(): bool
    {
        return !$this->warning &&
               !$this->error &&
               !$this->critical &&
               !$this->processFailed &&
               !$this->processTimeout;
    }

    public function getNtfsErrors(): array
    {
        return $this->ntfsErrors;
    }

    /**
     * Create an empty filesystem integrity summary.
     */
    public static function createEmpty(): FilesystemIntegritySummary
    {
        return new FilesystemIntegritySummary([], [], [], [], [], [], []);
    }

    /**
     * Create a filesystem integrity summary based on filesystem check results.
     *
     * @param FilesystemCheckResult[] $filesystemCheckResults
     * @param Volume[] $volumes
     */
    public static function createFromFilesystemCheckResults(
        array $filesystemCheckResults,
        array $volumes
    ): FilesystemIntegritySummary {
        $healthy = [];
        $warning = [];
        $error = [];
        $critical = [];
        $failedProcess = [];
        $ntfsErrors = [];
        $processTimeout = [];

        // Place volume into appropriate severity bucket
        foreach ($filesystemCheckResults as $filesystemCheckResult) {
            $resultCode = $filesystemCheckResult->getResultCode();
            $volumeMetadata = $filesystemCheckResult->getVolumeMetadata();

            // Error and Warning currently only apply to ntfs and unknown filesystem types and is probably undesired behavior.
            // See BCDR-15230 and the comments on BCDR-14956 that indicate "a large percentage of the linux systems would be surfacing errors.
            // It was believed that these warnings were "less than critical" errors would not impact our ability to use the restore point."
            $filesystem = self::getFilesystem($volumeMetadata->getGuid(), $volumes);
            $isNtfsOrNull = $filesystem === self::FILESYSTEM_TYPE_NTFS || $filesystem === null;

            if (in_array($resultCode, self::PROCESS_FAILED_FILESYSTEM_CODES)) {
                $failedProcess[] = $volumeMetadata;
            } elseif (in_array($resultCode, self::PROCESS_TIMEOUT_FILESYSTEM_CODES)) {
                    $processTimeout[] = $volumeMetadata;
            } elseif (in_array($resultCode, self::CRITICAL_FILESYSTEM_CODES)) {
                $critical[] = $volumeMetadata;
            } elseif ($isNtfsOrNull && in_array($resultCode, self::ERROR_FILESYSTEM_CODES)) {
                $error[] = $volumeMetadata;
            } elseif ($isNtfsOrNull && in_array($resultCode, self::WARNING_FILESYSTEM_CODES)) {
                $warning[] = $volumeMetadata;
            } else {
                $healthy[] = $volumeMetadata;
            }

            $resultDetails = $filesystemCheckResult->getResultDetails();

            if ($resultDetails) {
                $ntfsErrors[] = [
                    'volume' => $volumeMetadata,
                    'trans_ids' => self::getTranslationIds($resultDetails)
                ];
            }
        }

        return new FilesystemIntegritySummary(
            $healthy,
            $warning,
            $error,
            $critical,
            $failedProcess,
            $processTimeout,
            $ntfsErrors
        );
    }

    private static function getFilesystem(string $volumeGuid, array $volumes): ?string
    {
        foreach ($volumes as $volume) {
            if ($volume->getGuid() === $volumeGuid) {
                return strtolower($volume->getFilesystem());
            }
        }

        return null;
    }

    private static function getTranslationIds(array $resultDetails): array
    {
        $translations = [];

        $refl = new ReflectionClass(NtfsResizeCheckResultDetails::class);
        $constants = $refl->getConstants();

        foreach ($constants as $name => $value) {
            if (substr($name, 0, 6) === 'ERROR_' &&
                in_array($value, $resultDetails)
            ) {
                $name = substr($name, 6);
                $name = strtolower(str_replace('_', '', $name));

                $translations[] = trim($name);
            }
        }

        return $translations;
    }
}
