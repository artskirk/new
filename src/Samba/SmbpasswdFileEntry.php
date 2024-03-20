<?php

namespace Datto\Samba;

/**
 * Represents a single line in the an smbpasswd format of exported smbpasswd:<exported password file name> file.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class SmbpasswdFileEntry
{
    /** @var string */
    private $line;

    /** @var string[] */
    private $parts;

    /**
     * @param string $line A single line of an smbPassword file.
     */
    public function __construct(string $line)
    {
        $this->line = rtrim($line, PHP_EOL);
        $this->parts = explode(':', $this->line);
    }

    /**
     * Gets the file line with the trailing newline.
     *
     * @return string
     */
    public function getLineWithEol(): string
    {
        return $this->line . PHP_EOL;
    }

    /**
     * Gets the user name.
     *
     * @return string
     */
    public function getName(): string
    {
        if (empty($this->parts[0])) {
            throw new SambaPasswdFileException("Name field in samba database is empty. The database is corrupt.");
        }
        return $this->parts[0];
    }

    /**
     * Gets the password hash
     *
     * @return string
     */
    public function getPwHash(): string
    {
        if (empty($this->parts[3])) {
            throw new SambaPasswdFileException("Error password hash in samba database for samba user {$this->parts[0]} is empty. The database is corrupt");
        }
        return $this->parts[3];
    }

    /**
     * sets the entry Uid (linux User Id)
     *
     * @param string
     */
    public function setUid(string $uid)
    {
        $this->parts[1] = $uid;
        $this->line = implode(':', $this->parts);
    }
}
