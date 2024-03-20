<?php

namespace Datto\User;

use Datto\Common\Utility\Filesystem;
use Datto\User\WebAccessFileException;
use Exception;

/**
 * Manages the "/datto/config/local/webaccess" file.
 *
 * @author John Fury Christ <jchrista@datto.com>
 */
class WebAccessFileRepository
{
    /** @var string */
    private $filename;

    /** @var int */
    private $permissions;

    /** @var string */
    private $group;

    /** @var Filesystem */
    private $filesystem;

    /** @var WebAccessFileEntry[] */
    private $webAccessFileEntries;

    /**
     * @param string $filename Absolute path to webaccess file.
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
        $this->webAccessFileEntries = [];
    }

    /**
     * Loads the system webaccess file.
     */
    public function load()
    {
        $this->webAccessFileEntries = [];
        if (!$this->filesystem->exists($this->filename)) {
            return;
        }
        $fileArray = $this->filesystem->file($this->filename);
        if ($fileArray === false) {
            throw new WebAccessFileException("Error reading webAccess file");
        }
        foreach ($fileArray as $fileLine) {
            $this->webAccessFileEntries[] = new WebAccessFileEntry($fileLine);
        }
    }

    /**
     * Saves the system webAccess file.
     */
    public function save()
    {
        $contents = '';
        foreach ($this->webAccessFileEntries as $webAccessEntry) {
            $contents .= $webAccessEntry->getLineWithEol();
        }
        $newFilename = $this->filename . '.new';
        if ($this->filesystem->filePutContents($newFilename, $contents) === false) {
            throw new WebAccessFileException("Error writing new webAccess file");
        }
        if ($this->permissions && !$this->filesystem->chmod($newFilename, $this->permissions)) {
            throw new WebAccessFileException("Error setting permissions on new webAccess file");
        }
        if ($this->group && !$this->filesystem->chgrp($newFilename, $this->group)) {
            throw new WebAccessFileException("Error changing group on new webAccess file");
        }
        if (!$this->filesystem->rename($newFilename, $this->filename)) {
            throw new WebAccessFileException("Error updating webAccess file");
        }
    }

    /**
     * Adds a single user to the webaccess list.
     *
     * @param WebAccessFileEntry $webAccessFileEntry
     */
    public function add(WebAccessFileEntry $webAccessFileEntry)
    {
        if ($this->nameExists($webAccessFileEntry->getName())) {
            throw new DuplicateUserException('Not allowed to add duplicate user name');
        }
        $this->webAccessFileEntries[] = $webAccessFileEntry;
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
        foreach ($this->webAccessFileEntries as $key => $webAccessEntry) {
            if (in_array($webAccessEntry->getName(), $usernames)) {
                $deletedUserNames[] = $webAccessEntry->getName();
                unset($this->webAccessFileEntries[$key]);
            }
        }
        return $deletedUserNames;
    }

    /**
     * Gets the user entry which matches the given user name.
     *
     * @param string $name
     * @return WebAccessFileEntry|null
     */
    public function getByName(string $name)
    {
        foreach ($this->webAccessFileEntries as $webAccessEntry) {
            $webAccessEntryName = $webAccessEntry->getName();
            if ($webAccessEntryName === $name || $webAccessEntryName === "#$name") {
                return $webAccessEntry;
            }
        }
        return null;
    }

    /**
     * Determines if a username exists in the webAccess list.
     *
     * @param string $name Username to check for
     * @return bool true if the user exists
     */
    public function nameExists(string $name)
    {
        foreach ($this->webAccessFileEntries as $webAccessEntry) {
            if ($webAccessEntry->getName() === $name) {
                return true;
            }
        }
        return false;
    }
}
