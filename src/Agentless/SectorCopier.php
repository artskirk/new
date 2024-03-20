<?php

namespace Datto\Agentless;

use Exception;
use Datto\Common\Utility\Filesystem;

/**
 * Encapsulates copy operations from a backup source to destination.
 *
 * @author Jason Lodice <JLodice@datto.com>
 */
class SectorCopier
{
    /** @var int default copy chunk size (2MiB) */
    public const CHUNK_SIZE_IN_MIB = 2097152;

    /** @var int */
    private const SEEK_SUCCESS = 0;

    /** @var bool true if openFiles has been called */
    private $filesOpened;

    /** @var string file path to source data */
    private $sourcePath;

    /** @var string file path to destination data */
    private $destPath;

    /** @var resource handle for source file */
    private $sourceHandle;

    /** @var resource handle for backup destination */
    private $destHandle;

    /** @var bool true if only differences are written to backup destination */
    private $diffMerge;

    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem $filesystem
     * @param string $sourcePath file path to source data
     * @param string $destPath file path to backup destination
     * @param bool $diffMerge true if only differences are written to backup destination
     */
    public function __construct(Filesystem $filesystem, $sourcePath, $destPath, $diffMerge)
    {
        $this->filesOpened = false;
        $this->sourcePath = $sourcePath;
        $this->sourceHandle = null;
        $this->destPath = $destPath;
        $this->destHandle = null;
        $this->diffMerge = $diffMerge;
        $this->filesystem = $filesystem;
    }

    /**
     * Open the source and destination files for reading/writing.
     * If called more than once, is a no-op.
     */
    public function openFiles(): void
    {
        if (!$this->filesOpened) {
            $this->filesOpened = true;
            $this->sourceHandle = $this->openSourceImage();
            $this->destHandle = $this->openDestinationImage();
        }
    }

    /**
     * Copies sectors from source (remote) VMDK to local raw file.
     *
     * Handles both incremental and full backups.
     *
     * @param OffsetMap $offsetMap
     * @param callable $progressCallback function to report SectorCopyProgress
     * @param callable $abortCallback function returns true if copy should abort
     * @return SectorCopyResult
     */
    public function copy(OffsetMap $offsetMap, $progressCallback, $abortCallback)
    {
        if (!$this->filesOpened) {
            throw new Exception("must call openFiles() before beginning copy");
        }

        $bytesReadTotal = 0;
        $bytesWrittenTotal = 0;

        // Seek to initial position in source file
        $srcStartingPosition = $offsetMap->getSourceByteOffset();
        $srcSeekSuccess = $this->filesystem->seek($this->sourceHandle, $srcStartingPosition, SEEK_SET);
        if ($srcSeekSuccess !== self::SEEK_SUCCESS) {
            throw new Exception(sprintf("Error seeking source file to byte %s", $srcStartingPosition));
        }

        // Seek to initial position in destination file
        $currentDestPosition = $offsetMap->getDestByteOffset();
        $dstSeekSuccess = $this->filesystem->seek($this->destHandle, $currentDestPosition, SEEK_SET);
        if ($dstSeekSuccess !== self::SEEK_SUCCESS) {
            throw new Exception(sprintf("Error seeking destination file to byte %s", $currentDestPosition));
        }

        $bytesTotal = $offsetMap->getByteLength();

        // Chunk size stays same until last copy
        $chunkSize = static::CHUNK_SIZE_IN_MIB;

        while ($bytesReadTotal < $bytesTotal) {
            // Ask caller if we should abort
            if ($abortCallback !== null) {
                if ($abortCallback()) {
                    //  return result with abort bit set
                    return new SectorCopyResult($bytesReadTotal, $bytesWrittenTotal, $bytesTotal, true);
                }
            }

            $remaining = $bytesTotal - $bytesReadTotal;

            if ($remaining < $chunkSize) {
                $chunkSize = $remaining;
            }
            $data = $this->readSource($chunkSize);

            $bytesReadTotal += $chunkSize;

            // Write chunk, pass current file position which is necessary for error detection
            $newBytesWritten = $this->writeToDestination($data, $chunkSize, $currentDestPosition);
            $bytesWrittenTotal += $newBytesWritten;

            // Advance destination file position
            $currentDestPosition += $chunkSize;

            // See if caller wants a progress report
            if ($progressCallback !== null) {
                $progressReport = new SectorCopyProgress($chunkSize, $newBytesWritten);
                $progressCallback($progressReport);
            }
        }

        return new SectorCopyResult($bytesReadTotal, $bytesWrittenTotal, $bytesTotal, false);
    }

    /**
     * Close the source and destination files.
     * If called more than once, will be a no-op.
     */
    public function closeFiles(): void
    {
        if ($this->filesOpened) {
            $this->filesOpened = false;
            $this->filesystem->close($this->sourceHandle);
            $this->filesystem->close($this->destHandle);
            $this->sourceHandle = null;
            $this->destHandle = null;
        }
    }

    /**
     * Opens source file for reading.
     * @return resource
     */
    private function openSourceImage()
    {
        $sourceHandle = $this->filesystem->open($this->sourcePath, 'rb');

        if (false === $sourceHandle) {
            throw new \RuntimeException(sprintf(
                'Unable to open source VMDK: %s',
                $this->sourcePath
            ));
        }

        return $sourceHandle;
    }

    /**
     * Opens destination file for writing.
     * @return resource
     */
    private function openDestinationImage()
    {
        $destHandle = $this->filesystem->open($this->destPath, 'r+');

        if (false === $destHandle) {
            throw new \RuntimeException(sprintf(
                'Unable to open destination .datto file: %s',
                $this->destPath
            ));
        }

        return $destHandle;
    }

    /**
     * Writes bytes to destination image file.
     *
     * If backup was started with diffMerge = true argument, it will only write
     * the bytes that are different.
     *
     * @param string $data
     * @param int $length
     * @param int $startDestPosition current position of destination file (bytes from start)
     * @return int bytes written
     */
    private function writeToDestination($data, $length, $startDestPosition)
    {
        // Assume we will write
        $doWrite = true;

        $bytesWritten = 0;
        if ($this->diffMerge) {
            // Compute crc on source
            $sourceSum = crc32($data);

            // Read from destination, compute crc
            $destSum = crc32($this->readDestination($length));

            // Only write if crc is different
            $doWrite = $sourceSum !== $destSum;

            // If we are going to write, seek the dest file backwards to correct location
            if ($doWrite) {
                $dstSeekSuccess = $this->filesystem->seek($this->destHandle, -$length, SEEK_CUR);
                // Destination position should be back to where we started before we read
                if ($dstSeekSuccess !== self::SEEK_SUCCESS) {
                    throw new Exception("Error seeking destination backwards before write, diffMerge enabled.");
                }
            }
        }

        if ($doWrite) {
            $bytesWritten = $this->filesystem->write($this->destHandle, $data, $length);
            if ($bytesWritten != $length) {
                throw new Exception(
                    sprintf(
                        "Error writing to destination file '%s', expected %s bytes, actual %s bytes.",
                        $this->destPath,
                        $length,
                        $bytesWritten
                    )
                );
            }
        }

        return $bytesWritten;
    }

    /**
     * @param int $chunkSize bytes to read
     * @return string data read
     */
    public function readSource($chunkSize)
    {
        // Read data
        $data = $this->filesystem->read($this->sourceHandle, $chunkSize);
        if (false === $data) {
            throw new Exception(
                sprintf("Error reading from source file '%s'.", $this->sourcePath)
            );
        } else {
            $actualBytesRead = strlen($data);
            if ($actualBytesRead != $chunkSize) {
                throw new Exception(
                    sprintf(
                        "Error reading from source file '%s'. Expected bytes %s, actual bytes %s.",
                        $this->sourcePath,
                        $chunkSize,
                        $actualBytesRead
                    )
                );
            }
        }
        return $data;
    }

    /**
     * @param int $chunkSize bytes to read
     * @return string data read
     */
    public function readDestination($chunkSize)
    {
        //  read data
        $data = $this->filesystem->read($this->destHandle, $chunkSize);
        if (false === $data) {
            throw new Exception(
                sprintf("Error reading from destination file '%s'.", $this->destPath)
            );
        } else {
            $actualBytesRead = strlen($data);
            if ($actualBytesRead != $chunkSize) {
                throw new Exception(
                    sprintf(
                        "Error reading from destination file '%s'. Expected bytes %s, actual bytes %s.",
                        $this->destPath,
                        $chunkSize,
                        $actualBytesRead
                    )
                );
            }
        }
        return $data;
    }
}
