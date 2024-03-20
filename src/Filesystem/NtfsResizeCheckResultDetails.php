<?php

namespace Datto\Filesystem;

/**
 * Class to handle string parsing of specific error details for NTFSResize filesystem checks
 */
class NtfsResizeCheckResultDetails
{
    // These constants are mapped to translations through reflection in FilesystemIntegritySummary.php. For example:
    //   ERROR_BAD_SECTORS is mapped to assets.recoverypoints.verification.dropdown.integrity.filesystem.badsectors
    const ERROR_BAD_SECTORS = 'bad sectors';
    const ERROR_BAD_SECTOR_LIST = 'bad sector list';
    const ERROR_BITMAP_TOO_SMALL = 'ERROR: $Bitmap size is smaller than expected';
    const ERROR_CLUSTER_ACCOUNTING_MISMATCHES = 'cluster accounting mismatches';
    const ERROR_CLUSTERS_REFERENCED_MULTIPLE_TIMES = 'clusters are referenced multiple times';
    const ERROR_HIGHLY_FRAGMENTED_BITMAP = 'Highly fragmented $Bitmap';
    const ERROR_INODE_CORRUPTION = 'Inode is corrupt';
    const ERROR_INPUT_OUTPUT = 'Input/output';
    const ERROR_IS_MOUNTED = 'is mounted';
    const ERROR_NO_SUCH_FILE = 'No such file or directory';
    const ERROR_NTFS_DECOMPRESS_MAPPING_PAIRS = 'ntfs_decompress_mapping_pairs';
    const ERROR_NTFS_IS_INCONSISTENT = 'NTFS is inconsistent';
    const ERROR_NTFS_MST_POST_READ_FIXUP = 'ntfs_mst_post_read_fixup';
    const ERROR_VOLUME_IS_FULL = 'Volume is full';
    const ERROR_CLUSTERS_ARE_REFERENCED_OUTSIDE_OF_THE_VOLUME = 'clusters are referenced outside of the volume';

    const UNPASSABLE_ERROR_ARR = [
        self::ERROR_BAD_SECTOR_LIST,
        self::ERROR_BITMAP_TOO_SMALL,
        self::ERROR_CLUSTERS_REFERENCED_MULTIPLE_TIMES,
        self::ERROR_INODE_CORRUPTION,
        self::ERROR_INPUT_OUTPUT,
        self::ERROR_NO_SUCH_FILE,
        self::ERROR_NTFS_DECOMPRESS_MAPPING_PAIRS,
        self::ERROR_NTFS_MST_POST_READ_FIXUP,
        self::ERROR_CLUSTERS_ARE_REFERENCED_OUTSIDE_OF_THE_VOLUME
    ];

    const PASSABLE_ERRORS_ARR = [
        self::ERROR_BAD_SECTORS,
        self::ERROR_HIGHLY_FRAGMENTED_BITMAP,
        self::ERROR_IS_MOUNTED,
        self::ERROR_VOLUME_IS_FULL,
        self::ERROR_NTFS_IS_INCONSISTENT,
        self::ERROR_CLUSTER_ACCOUNTING_MISMATCHES
    ];

    /**
     * Does ntfsresize process output indicate the error encountered is not critical to restore or use of data on
     * the image file
     *
     * Returns true if the result text indicates only known "passable" errors, and no "unpassable" errors
     * Returns false if any "unpassable" error is encountered, or if no "passable" errors are confirmed
     */
    public function parseDetailsForResultOverrideToPass(string $processOutput): bool
    {
        // A single unpassable error indicates
        foreach (self::UNPASSABLE_ERROR_ARR as $unpassableError) {
            if ($this->parseForString($processOutput, $unpassableError)) {
                return false;
            }
        }

        $foundPassableError = false;
        foreach (self::PASSABLE_ERRORS_ARR as $passableError) {
            if ($this->parseForString($processOutput, $passableError)) {
                $foundPassableError = true;
            }
        }
        return $foundPassableError;
    }

    /**
     * Take output from ntfsresize process and check against known error strings
     *
     * Return is an array of formatted string of errors found, or an empty array if no known errors matched
     */
    public function parseDetails(string $processOutput): array
    {
        $return = [];
        $errorArray = array_merge(self::UNPASSABLE_ERROR_ARR, self::PASSABLE_ERRORS_ARR);
        foreach ($errorArray as $errorString) {
            if ($this->parseForString($processOutput, $errorString)) {
                $return[] = $errorString;
            }
        }
        return $return;
    }

    private function parseForString(string $processOutput, string $errorString)
    {
        return (strpos($processOutput, $errorString) !== false);
    }
}
