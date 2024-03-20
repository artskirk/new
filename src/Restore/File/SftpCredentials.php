<?php

namespace Datto\Restore\File;

/**
 * Class to represent SFTP user and password
 *
 * @author Marcus Recck <mr@datto.com>
 */
class SftpCredentials
{
    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /**
     * @param string $username
     * @param string $password
     */
    public function __construct(string $username, string $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }
}
