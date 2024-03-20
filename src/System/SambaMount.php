<?php

namespace Datto\System;

/**
 * Class SambaMount
 *
 * The information necessary to mount a samba share
 * todo: A complete implementation would support all mount.cifs options. This is a small subset.
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class SambaMount
{
    /** @var string */
    private $host;

    /** @var string */
    private $folder;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var string */
    private $domain;

    /** @var bool */
    private $readOnly;

    /** @var bool */
    private $includeCifsAcls;

    /** @var string */
    private $passwordKey;

    /**
     * @param string $host
     * @param string $folder
     * @param string|null $username
     * @param string|null $password
     * @param string|null $domain
     * @param bool|false $readOnly
     * @param bool|false $acls
     * @param string|null $passwordKey
     */
    public function __construct(
        $host,
        $folder,
        $username = null,
        $password = null,
        $domain = null,
        $readOnly = false,
        bool $acls = false,
        $passwordKey = null
    ) {
        $this->host = $host;
        $this->folder = $folder;
        $this->username = $username;
        $this->password = $password;
        $this->domain = $domain;
        $this->readOnly = $readOnly;
        $this->includeCifsAcls = $acls;
        $this->passwordKey = $passwordKey;
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @return string
     */
    public function getFolder()
    {
        return $this->folder;
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @return string
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /**
     * @return boolean
     */
    public function isReadOnly()
    {
        return $this->readOnly;
    }

    /**
     * Whether to present Windows ACLs as xattrs in the mount or not (cifsacl).
     * @return bool
     */
    public function includeCifsAcls(): bool
    {
        return $this->includeCifsAcls;
    }

    /**
     * Encryption key for the password, should only be used in the serializer.
     * @return string|null
     */
    public function getPasswordKey()
    {
        return $this->passwordKey;
    }
}
