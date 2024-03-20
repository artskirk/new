<?php

namespace Datto\User;

use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Manages the "/etc/shadow" file.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class ShadowFileRepository
{
    /** @var string */
    private $filename;

    /** @var int */
    private $permissions;

    /** @var string */
    private $group;

    /** @var Filesystem */
    private $filesystem;

    /** @var ShadowFileEntry[] */
    private $shadowFileEntries;

    /**
     * @param string $filename Absolute path to shadow file.
     * @param int $permissions File permissions to set on save or 0 for default.
     * @param string $group File group to set on save or '' for default.
     * @param Filesystem $filesystem
     */
    public function __construct(
        string $filename,
        int $permissions,
        string $group,
        Filesystem $filesystem
    ) {
        $this->filename = $filename;
        $this->permissions = $permissions;
        $this->group = $group;
        $this->filesystem = $filesystem;
        $this->shadowFileEntries = [];
    }

    /**
     * Loads the system shadow file.
     */
    public function load()
    {
        $fileArray = $this->filesystem->file($this->filename);
        if ($fileArray === false) {
            throw new Exception("Error reading shadow file");
        }
        $this->shadowFileEntries = [];
        foreach ($fileArray as $fileLine) {
            $this->shadowFileEntries[] = new ShadowFileEntry($fileLine);
        }
    }

    /**
     * Saves the system shadow file.
     */
    public function save()
    {
        $contents = '';
        foreach ($this->shadowFileEntries as $shadowEntry) {
            $contents .= $shadowEntry->getLineWithEol();
        }
        $newFilename = $this->filename . '.new';
        if ($this->filesystem->filePutContents($newFilename, $contents) === false) {
            throw new Exception("Error writing new shadow file");
        }
        if ($this->permissions && !$this->filesystem->chmod($newFilename, $this->permissions)) {
            throw new Exception("Error setting permissions on new shadow file");
        }
        if ($this->group && !$this->filesystem->chgrp($newFilename, $this->group)) {
            throw new Exception("Error changing group on new shadow file");
        }
        if (!$this->filesystem->rename($newFilename, $this->filename)) {
            throw new Exception("Error updating shadow file");
        }
    }

    /**
     * Adds a single user to the shadow list.
     *
     * @param ShadowFileEntry $shadowEntry
     */
    public function add(ShadowFileEntry $shadowEntry)
    {
        if ($this->nameExists($shadowEntry->getName())) {
            throw new Exception('Not allowed to add duplicate user name');
        }

        $this->shadowFileEntries[] = $shadowEntry;
    }

    /**
     * Deletes users which match the given user names.
     *
     * @param string[] List of user names to delete
     * @return string[] List of user names that were deleted
     */
    public function deleteUsersByName(array $usernames): array
    {
        $deletedUserNames = [];
        foreach ($this->shadowFileEntries as $key => $shadowEntry) {
            if (in_array($shadowEntry->getName(), $usernames)) {
                $deletedUserNames[] = $shadowEntry->getName();
                unset($this->shadowFileEntries[$key]);
            }
        }
        return $deletedUserNames;
    }

    /**
     * Gets the user entry which matches the given user name.
     *
     * @param string $name
     * @return ShadowFileEntry|null
     */
    public function getByName(string $name)
    {
        foreach ($this->shadowFileEntries as $shadowEntry) {
            if ($shadowEntry->getName() === $name) {
                return $shadowEntry;
            }
        }
        return null;
    }

    /**
     * Determines if a username exists in the shadow list.
     *
     * @param string $name Username to check for
     * @return bool true if the user exists
     */
    public function nameExists(string $name)
    {
        foreach ($this->shadowFileEntries as $shadowEntry) {
            if ($shadowEntry->getName() === $name) {
                return true;
            }
        }
        return false;
    }
}
