<?php

namespace Datto\User\Lockout;

use Datto\Config\JsonConfigRecord;

/**
 * Represents a user in the failedLogins file
 * @author Christopher Bitler <cbitler@datto.com>
 */
class FailedLoginUser extends JsonConfigRecord
{
    const FAILED_LOGIN_DIRECTORY = 'failedLogins/';

    /** @var string */
    private $username;

    /** @var array[] */
    private $failedAttempts = [];

    /** @var int */
    private $lockedOutAt = 0;

    /**
     * Create a new FailedLoginUser object
     * @param string $username
     */
    public function __construct(string $username)
    {
        $this->username = $username;
    }

    /**
     * Get the username that this user is associated with
     *
     * @return string The username associated with this user
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Get the list of failed login attempts for user by timestamp
     *
     * @return int[] The list of failed login attempt timestamps
     */
    public function getFailedAttempts(): array
    {
        return $this->failedAttempts;
    }

    /**
     * Add a new failed login attempt to the list of failed attempts
     *
     * @param int $timestamp The timestamp of the failed login
     */
    public function addFailedAttempt(int $timestamp)
    {
        $this->failedAttempts[] = $timestamp;
    }

    /**
     * Set the contents of the list of failed login attempts for this user
     *
     * @param array $attempts Array of failed attempt timestamps
     */
    public function setFailedAttempts(array $attempts)
    {
        $this->failedAttempts = $attempts;
    }

    /**
     * Reset the list of failed attempts for this user
     */
    public function resetFailedAttempts()
    {
        $this->failedAttempts = [];
    }

    /**
     * Get the last time this user was locked out
     *
     * @return int The epoch time for the last time the was locked out
     */
    public function getLastLockoutTime(): int
    {
        return $this->lockedOutAt;
    }

    /**
     * Set the last time the user was locked out
     *
     * @param int $time The epoch time that the user was locked out at
     */
    public function setLastLockoutTime(int $time)
    {
        $this->lockedOutAt = $time;
    }

    /**
     * {@inheritdoc}
     */
    public function getKeyName(): string
    {
        return self::FAILED_LOGIN_DIRECTORY . $this->getUsername();
    }

    /**
     * {@inheritdoc}
     */
    protected function load(array $vals)
    {
        $this->setLastLockoutTime($vals['lastLockedOut'] ?? 0);
        $this->setFailedAttempts($vals['failedAttempts'] ?? []);
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'failedAttempts' => $this->getFailedAttempts(),
            'lastLockedOut' => $this->getLastLockoutTime()
        ];
    }
}
