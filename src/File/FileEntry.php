<?php

namespace Datto\File;

/**
 * Holds all the information that the file browser needs to display a file.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class FileEntry
{
    private string $name;
    private int $size;
    private string $relativePath;
    private int $changedTime;
    private int $modifiedTime;
    private bool $isDir;
    private bool $isLink;

    /** @var FileEntry[]|null */
    private ?array $directoryContents;

    private ?string $downloadHref;
    private ?string $browseHref;

    /**
     * @param string $name
     * @param int $size
     * @param string $relativePath
     * @param int $changedTime
     * @param int $modifiedTime
     * @param bool $isDir
     * @param bool $isLink
     * @param FileEntry[]|null $directoryContents
     * @param string|null $downloadHref
     * @param string|null $browseHref
     */
    public function __construct(
        string $name,
        int $size,
        string $relativePath,
        int $changedTime,
        int $modifiedTime,
        bool $isDir,
        bool $isLink,
        array $directoryContents = null,
        string $downloadHref = null,
        string $browseHref = null
    ) {
        $this->name = $name;
        $this->size = $size;
        $this->relativePath = $relativePath;
        $this->changedTime = $changedTime;
        $this->modifiedTime = $modifiedTime;
        $this->isDir = $isDir;
        $this->isLink = $isLink;
        $this->directoryContents = $directoryContents;
        $this->downloadHref = $downloadHref;
        $this->browseHref = $browseHref;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getRelativePath(): string
    {
        return $this->relativePath;
    }

    public function getChangedTime(): int
    {
        return $this->changedTime;
    }

    public function getModifiedTime(): int
    {
        return $this->modifiedTime;
    }

    public function isDir(): bool
    {
        return $this->isDir;
    }

    public function isLink(): bool
    {
        return $this->isLink;
    }

    /**
     * @return FileEntry[]|null
     */
    public function getDirectoryContents(): ?array
    {
        return $this->directoryContents;
    }

    /**
     * @param FileEntry[] $directoryContents
     */
    public function setDirectoryContents(array $directoryContents): void
    {
        $this->directoryContents = $directoryContents;
    }

    public function getDownloadHref(): ?string
    {
        return $this->downloadHref;
    }

    public function setDownloadHref(string $downloadHref): void
    {
        $this->downloadHref = $downloadHref;
    }

    public function getBrowseHref(): ?string
    {
        return $this->browseHref;
    }

    public function setBrowseHref(string $browseHref): void
    {
        $this->browseHref = $browseHref;
    }

    public function toArray(): array
    {
        if ($this->directoryContents) {
            $directoryContents = [];
            foreach ($this->directoryContents as $fileEntry) {
                $directoryContents[] = $fileEntry->toArray();
            }
        } else {
            $directoryContents = null;
        }

        return [
            'name' => $this->getName(),
            'size' => $this->getSize(),
            'relativePath' => $this->getRelativePath(),
            'changedTime' => $this->getChangedTime(),
            'modifiedTime' => $this->getModifiedTime(),
            'isDir' => $this->isDir(),
            'isLink' => $this->isLink(),
            'directoryContents' => $directoryContents,
            'downloadHref' => $this->getDownloadHref(),
            'browseHref' => $this->getBrowseHref()
        ];
    }
}
