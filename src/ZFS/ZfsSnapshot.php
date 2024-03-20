<?php

namespace Datto\ZFS;

use UnexpectedValueException;

/**
 * Represents a ZFS dataset snapshot
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class ZfsSnapshot
{
    /** @var string */
    private $fullName;

    public function __construct(string $fullName)
    {
        if (strpos($fullName, '@') === false) {
            throw new UnexpectedValueException(sprintf(
                'Unexpected snapshot name, expecting <zfs_path>@<name> got "%s"',
                $fullName
            ));
        }

        $this->fullName = $fullName;
    }

    /**
     * Get the name of ZFS snapshot.
     *
     * The custom name given at creation time (the one that follows @ character)
     * This is usually the backup timestamp used for UI display - the time of
     * when the backup started.
     *
     * @return string
     */
    public function getName(): string
    {
        $namePos = strrpos($this->fullName, '@') + 1;

        return substr($this->fullName, $namePos);
    }

    /**
     * Get the full name of the snapshot.
     *
     * This name includes dataset path and can be used directly with zfs
     * commands operating on snapshots "as is".
     *
     * @return string
     */
    public function getFullName(): string
    {
        return $this->fullName;
    }
}
