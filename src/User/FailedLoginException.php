<?php

namespace Datto\User;

use Datto\User\Lockout\LoginLockoutService;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Exception thrown when a user fails to log in or authenticate correctly or is locked out from logging in
 * @author Christopher Bitler <cbitler@datto.con>
 */
class FailedLoginException extends AuthenticationException
{
    /** @var int */
    private $attemptsLeft;

    /** @var int */
    private $timeLeftInLockout;

    /**
     * Create a new instance of FailedLoginException with the specified number of attempts left and time left in
     * lockout if applicable
     *
     * @param int $attemptsLeft
     * @param int $timeLeftInLockout
     */
    public function __construct(
        int $attemptsLeft = LoginLockoutService::ATTEMPTS_BEFORE_LOCKOUT,
        int $timeLeftInLockout = 0
    ) {
        parent::__construct();
        $this->attemptsLeft = $attemptsLeft;
        $this->timeLeftInLockout = $timeLeftInLockout;
    }

    /**
     * Get the number of attempts left before a login lockout
     *
     * @return int
     */
    public function getAttemptsLeft(): int
    {
        return $this->attemptsLeft;
    }

    /**
     * Get the time left in the login lockout related to this exception, if applicable
     *
     * @return int
     */
    public function getTimeLeftInLockout(): int
    {
        return $this->timeLeftInLockout;
    }
}
