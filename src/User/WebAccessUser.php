<?php

namespace Datto\User;

/**
 * @author Matthew Cheman <mcheman@datto.com>
 */
class WebAccessUser
{
    /** @var bool */
    private $enabled;

    /** @var string */
    private $username;

    /** @var string */
    private $passwordHash;

    /** @var string */
    private $roles;

    /**
     * @param bool $enabled
     * @param string $username
     * @param string $passwordHash
     * @param string $roles
     */
    public function __construct(bool $enabled, string $username, string $passwordHash, string $roles)
    {
        $this->enabled = $enabled;
        $this->username = $username;
        $this->passwordHash = $passwordHash;
        $this->roles = $roles;
    }

    /**
     * Returns whether the passed in password matches this user's password
     *
     * @param string $password The password to check
     * @return bool True if the password is valid, false if not
     */
    public function checkPassword(string $password): bool
    {
        if ($this->usesMd5()) {
            return $this->md5PasswordVerify($password, $this->passwordHash);
        } else {
            return password_verify($password, $this->passwordHash);
        }
    }

    /**
     * Returns whether or not the user's password hash is insecure and needs to be rehashed.
     * Call setPassword() to update the user's password to the current standard
     *
     * @return bool True if the hash needs updating, false otherwise
     */
    public function passwordNeedsRehash(): bool
    {
        return $this->usesMd5() || password_needs_rehash($this->passwordHash, PASSWORD_DEFAULT);
    }

    /**
     * Changes the users password
     *
     * @param string $password The new password
     */
    public function setPassword(string $password)
    {
        $this->passwordHash = password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Enables or disables web access for this user
     *
     * @param bool $enabled
     */
    public function setEnabled(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
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
    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return string
     */
    public function getRoles(): string
    {
        return $this->roles;
    }

    /**
     * @param string $roles
     */
    public function setRoles(string $roles)
    {
        $this->roles = $roles;
    }

    /**
     * @return bool True if the password hash looks like an md5 hash, otherwise false
     */
    private function usesMd5(): bool
    {
        return preg_match('/^[a-fA-F0-9]{32}$/', $this->passwordHash);
    }

    /**
     * Returns whether the user has a valid username/password pair by comparing it against a legacy md5 password hash
     *
     * @param string $password The password to check
     * @param string $passwordHash The md5 hash to check against
     * @return bool True if the password matches the password hash, otherwise false
     */
    private function md5PasswordVerify(string $password, string $passwordHash): bool
    {
        return hash_equals(md5($password), $passwordHash);
    }
}
