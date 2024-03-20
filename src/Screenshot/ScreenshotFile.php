<?php

namespace Datto\Screenshot;

/**
 * Encapsulates a single screenshot file (may be a text file.)
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ScreenshotFile
{
    /** @var string */
    private $assetKey;

    /** @var int */
    private $snapshotEpoch;

    /** @var string */
    private $file;

    /** @var int */
    private $modifiedAt;

    /** @var string */
    private $extension;

    public function __construct(string $assetKey, int $snapshotEpoch, string $file, int $modifiedAt, string $extension)
    {
        $this->assetKey = $assetKey;
        $this->snapshotEpoch = $snapshotEpoch;
        $this->file = $file;
        $this->modifiedAt = $modifiedAt;
        $this->extension = $extension;
    }

    /**
     * @return string
     */
    public function getAssetKey(): string
    {
        return $this->assetKey;
    }

    /**
     * @return int
     */
    public function getSnapshotEpoch(): int
    {
        return $this->snapshotEpoch;
    }

    /**
     * @return string
     */
    public function getFile(): string
    {
        return $this->file;
    }

    /**
     * Returns the remainder of the string that follows the '.' directly after the snapshotEpoch segment of the filename
     * This may include multiple words separated by periods.  Example: "gocr.txt"
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * @return int
     */
    public function getModifiedAt(): int
    {
        return $this->modifiedAt;
    }
}
