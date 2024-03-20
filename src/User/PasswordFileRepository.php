<?php

namespace Datto\User;

use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Manages the "/etc/passwd" file.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class PasswordFileRepository
{
    /** @var string */
    private $filename;

    /** @var int */
    private $permissions;

    /** @var string */
    private $group;

    /** @var Filesystem */
    private $filesystem;

    /** @var PasswordFileEntry[] */
    private $passwordFileEntries;

    /**
     * @param string $filename Absolute path to passwd file.
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
        $this->passwordFileEntries = [];
    }

    /**
     * Loads the system password file.
     */
    public function load()
    {
        $fileArray = $this->filesystem->file($this->filename);
        if ($fileArray === false) {
            throw new Exception("Error reading password file");
        }
        $this->passwordFileEntries = [];
        foreach ($fileArray as $fileLine) {
            $this->passwordFileEntries[] = new PasswordFileEntry($fileLine);
        }
    }

    /**
     * Gets the user entry which matches the given user name.
     *
     * @param string $name
     * @return PasswordFileEntry|null
     */
    public function getByName(string $name)
    {
        foreach ($this->passwordFileEntries as $passwordFileEntry) {
            if ($passwordFileEntry->getName() === $name) {
                return $passwordFileEntry;
            }
        }
        return null;
    }

    /**
     * Saves the system password file.
     */
    public function save()
    {
        $contents = '';
        foreach ($this->passwordFileEntries as $passwordEntry) {
            $contents .= $passwordEntry->getLineWithEol();
        }
        $newFilename = $this->filename . '.new';
        if ($this->filesystem->filePutContents($newFilename, $contents) === false) {
            throw new Exception("Error writing new passwd file");
        }
        if ($this->permissions && !$this->filesystem->chmod($newFilename, $this->permissions)) {
            throw new Exception("Error setting permissions on new passwd file");
        }
        if ($this->group && !$this->filesystem->chgrp($newFilename, $this->group)) {
            throw new Exception("Error changing group on new passwd file");
        }
        if (!$this->filesystem->rename($newFilename, $this->filename)) {
            throw new Exception("Error updating passwd file");
        }
    }

    /**
     * Gets the list of normal (non-system) users.
     *
     * @return PasswordFileEntry[]
     */
    public function getNormalUsers(): array
    {
        $normalUsers = [];
        foreach ($this->passwordFileEntries as $passwordEntry) {
            if ($passwordEntry->isNormalUser()) {
                $normalUsers[] = $passwordEntry;
            }
        }
        return $normalUsers;
    }

    /**
     * Adds a single user to the password list.
     *
     * @param PasswordFileEntry $passwordEntry
     */
    public function add(PasswordFileEntry $passwordEntry)
    {
        if ($this->nameExists($passwordEntry->getName())) {
            throw new Exception('Not allowed to add duplicate user name');
        }

        if ($this->uidExists($passwordEntry->getUid())) {
            throw new Exception('Not allowed to add duplicate user ID');
        }

        $this->passwordFileEntries[] = $passwordEntry;
    }

    /**
     * Deletes all normal (non-system) users from the password list.
     *
     * @return string[] List of user names that were deleted
     */
    public function deleteNormalUsers(): array
    {
        $deletedUserNames = [];
        foreach ($this->passwordFileEntries as $key => $passwordEntry) {
            if ($passwordEntry->isNormalUser()) {
                $deletedUserNames[] = $passwordEntry->getName();
                unset($this->passwordFileEntries[$key]);
            }
        }
        return $deletedUserNames;
    }

    /**
     * Determines if a username exists in the password list.
     *
     * @param string $name Username to check for
     * @return bool true if the user exists
     */
    public function nameExists(string $name)
    {
        foreach ($this->passwordFileEntries as $passwordEntry) {
            if ($passwordEntry->getName() == $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determines if a user ID exists in the password list.
     *
     * @param int $uid User ID to check for
     * @return bool true if the user exists
     */
    public function uidExists(int $uid)
    {
        foreach ($this->passwordFileEntries as $passwordEntry) {
            if ($passwordEntry->getUid() == $uid) {
                return true;
            }
        }
        return false;
    }
}
