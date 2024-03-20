<?php

namespace Datto\File;

use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Enumerates directories and returns FileEntry objects.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class FileEntryService
{
    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Generate FileEntry objects from a list of file paths.
     *
     * @param string $basePath
     * @param string[] $relativeFilePaths
     * @return FileEntry[]
     */
    public function getFileEntries(string $basePath, array $relativeFilePaths): array
    {
        $fileEntries = [];
        foreach ($relativeFilePaths as $relativePath) {
            $fullPath = $this->filesystem->join($basePath, $relativePath);
            $name = basename($relativePath);

            if ($name === '.' || $name === '..') {
                continue;
            }

            $stat = $this->filesystem->lstat($fullPath);

            // getSize throws an exception if the file is a broken symlink
            try {
                $size = $this->filesystem->getSize($fullPath);
                if ($size === false) {
                    $size = 0;
                }
            } catch (Exception $e) {
                $size = 0;
            }

            $fileEntry = new FileEntry(
                $name,
                $size,
                $relativePath,
                /**
                 * Sometimes `lstat` fails when ran against an unsupported reparse point,
                 * when that happens $stat is false, thus we default the `ctime` and `mtime` to 0
                 */
                $stat['ctime'] ?? 0,
                $stat['mtime'] ?? 0,
                $this->filesystem->isDir($fullPath),
                $this->filesystem->isLink($fullPath)
            );

            $fileEntries[] = $fileEntry;
        }

        return $fileEntries;
    }

    /**
     * Generate FileEntry objects for all items in a folder, optionally recursing multiple levels.
     *
     * @param string $basePath
     * @param string $relativePath
     * @param int $depth
     * @return FileEntry[]
     */
    public function getFileEntriesFromDir(string $basePath, string $relativePath, int $depth = 1)
    {
        // base case to return "no more files"
        if ($depth < 1) {
            return [];
        }

        $fullDirPath = $this->filesystem->join($basePath, $relativePath);
        $filenames = $this->filesystem->scandir($fullDirPath);
        $relativePaths = [];
        if ($filenames !== false) {
            foreach ($filenames as $filename) {
                $relativePaths[] = $this->filesystem->join($relativePath, $filename);
            }
        }
        $fileEntries = $this->getFileEntries($basePath, $relativePaths);

        foreach ($fileEntries as $fileEntry) {
            if ($fileEntry->isDir()) {
                $directoryContents = $this->getFileEntriesFromDir($basePath, $fileEntry->getRelativePath(), $depth - 1);
                $fileEntry->setDirectoryContents($directoryContents);
            }
        }

        return $fileEntries;
    }
}
