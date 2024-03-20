<?php

namespace Datto\Samba;

use Datto\Common\Utility\Filesystem;
use Datto\Samba\SambaPasswdFileException;

/**
 * Manages the samba password file -- text version.
 *
 * @author John Fury Christ <jchrista@datto.com>
 */
class SmbpasswdFileRepository
{
    /** @var string */
    private $filename;

    /** @var int */
    private $permissions;

    /** @var string */
    private $group;

    /** @var Filesystem */
    private $filesystem;

    /** @var SmbpasswdFileEntry[] */
    private $smbpasswdFileEntries;

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
        $this->smbpasswdFileEntries = [];
    }
    /**
     * returns an array of all smbpasswd.
     * @return SmbpasswdFileEntry[]
     */
    public function getAll()
    {
        return $this->smbpasswdFileEntries;
    }

    /**
     * Loads the system  smbpasswdEntries array.
     */
    public function load()
    {
        $this->smbpasswdFileEntries = [];
        if (!$this->filesystem->exists($this->filename)) {
            return;
        }
        $fileArray = $this->filesystem->file($this->filename);
        if ($fileArray === false) {
            throw new SambaPasswdFileException("Error reading text version of samba password file");
        }
        foreach ($fileArray as $fileLine) {
            $this->smbpasswdFileEntries[] = new SmbpasswdFileEntry($fileLine);
        }
    }

    /**
     * Saves the system smbPasswd file.
     */
    public function save()
    {
        $contents = '';
        foreach ($this->smbpasswdFileEntries as $smbpasswdEntry) {
            $contents .= $smbpasswdEntry->getLineWithEol();
        }
        if ($this->filesystem->filePutContents($this->filename, $contents) === false) {
            throw new SambaPasswdFileException("Error writing smbpasswd file");
        }
    }

    /**
     * Adds a single user to the smb list.
     *
     * @param SmbpasswdFileEntry $smbpasswdFileEntry
     */
    public function add(SmbpasswdFileEntry $smbpasswdFileEntry)
    {
        if ($this->nameExists($smbpasswdFileEntry->getName())) {
            throw new SambaPasswdFileException('Not allowed to add duplicate user name');
        }

        $this->smbpasswdFileEntries[] = $smbpasswdFileEntry;
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
        foreach ($this->smbpasswdFileEntries as $key => $smbpasswdFileEntry) {
            if (in_array($smbpasswdFileEntry->getName(), $usernames)) {
                $deletedUserNames[] = $smbpasswdFileEntry->getName();
                unset($this->smbpasswdFileEntries[$key]);
            }
        }
        return $deletedUserNames;
    }

    /**
     * Gets the samba password entry which matches the given samba user name.
     *
     * @param string $name
     * @return SmbpasswdFileEntry|null
     */
    public function getByName(string $name)
    {
        foreach ($this->smbpasswdFileEntries as $smbpasswdEntry) {
            if ($smbpasswdEntry->getName() == $name) {
                return $smbpasswdEntry;
            }
        }
        return null;
    }

    /**
     * Sets the user user id for the entry which matches the given user name.
     *
     * @param string $name
     * @param string $uid
     */
    public function setUidByName(string $name, string $uid)
    {
        $entryToChange = $this->getByName($name);
        $entryToChange->setUid($uid);
    }

    /**
     * Determines if a username exists in the SmbpasswdFileEntries list.
     *
     * @param string $name Username to check for
     * @return bool true if the user exists
     */
    public function nameExists(string $name): bool
    {
        foreach ($this->smbpasswdFileEntries as $smbpasswdEntry) {
            if ($smbpasswdEntry->getName() == $name) {
                return true;
            }
        }
        return false;
    }
}
