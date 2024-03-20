<?php

namespace Datto\User\Lockout;

use Datto\Config\ShmConfig;
use Datto\User\ShadowUser;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Throwable;

/**
 * Service for managing user lockouts for login
 * @author Christopher Bitler <cbitler@datto.com>
 */
class LoginLockoutService
{
    /** This is done in /dev/shm/ because it does not need to persist cross-reboot */
    const FAILED_LOGINS_DIRECTORY = '/dev/shm/failedLogins/';
    const ATTEMPTS_BEFORE_LOCKOUT = 5;
    const LOCKOUT_TIMESPAN = 300; // 5 minutes

    /** @var DateTimeService */
    private $timeService;

    /** @var ShmConfig */
    private $shmConfig;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        DateTimeService $timeService,
        ShmConfig $shmConfig,
        Filesystem $filesystem
    ) {
        $this->timeService = $timeService;
        $this->shmConfig = $shmConfig;
        $this->filesystem = $filesystem;
    }

    /**
     * Get how long is left in a user's current lockout in seconds
     *
     * @param string $username The user's name
     *
     * @return int The seconds left in the user's lockout (0 means they are not locked out)
     */
    public function getTimeLeftInLockout(string $username): int
    {
        $user = $this->getFailedLoginUser($username);

        $lastLockoutTime = $user->getLastLockoutTime();
        $timeDiff = $this->timeService->getTime() - $lastLockoutTime;
        return max(self::LOCKOUT_TIMESPAN - $timeDiff, 0);
    }

    /**
     * Reset the number of failed login attempts for a user
     *
     * @param string $username The username of the user to reset
     */
    public function resetFailedLogins(string $username)
    {
        $user = $this->getFailedLoginUser($username);
        $user->resetFailedAttempts();
        $this->saveLockoutFile($user);
    }

    /**
     * Add a failed login attempt for a user
     *
     * @param string $username The user's username
     */
    public function incrementFailedAttempts(string $username)
    {
        $failedLoginUser = $this->getFailedLoginUser($username);
        $failedLoginUser->addFailedAttempt($this->timeService->getTime());
        $lockedOut = $this->shouldLockout($failedLoginUser);

        if ($lockedOut) {
            $this->lockoutUser($failedLoginUser);
        }

        $this->saveLockoutFile($failedLoginUser);
    }

    /**
     * Get whether or not a user is locked out
     * This also tries to clear any outdated lockout files
     *
     * @param string $username The user's username
     * @return bool Whether or not the user is locked out
     */
    public function isLockedOut(string $username): bool
    {
        $this->clearOutdatedLockoutFiles();

        return $this->getTimeLeftInLockout($username) > 0;
    }

    /**
     * Get the remaining attempts that a user has left before being locked out.
     * Note: Failed login attempts are only counted if they occurred in the past five minutes.
     *
     * @param string $username The username of the user to check
     *
     * @return int The number of remaining attempts
     */
    public function getRemainingAttempts(string $username): int
    {
        $user = $this->getFailedLoginUser($username);
        $currentTime = $this->timeService->getTime();

        $callback = function ($failedAttempt) use ($currentTime) {
            return $currentTime - $failedAttempt < self::LOCKOUT_TIMESPAN;
        };

        $failedAttempts = array_filter($user->getFailedAttempts(), $callback);

        return self::ATTEMPTS_BEFORE_LOCKOUT - count($failedAttempts);
    }

    /**
     * Lock the user out
     *
     * @param FailedLoginUser $user The user to lock out
     */
    private function lockoutUser(FailedLoginUser $user)
    {
        $user->setLastLockoutTime($this->timeService->getTime());
    }

    /**
     * Save the file for a failed login user
     * Note: This does not save it if the username contains illegal characters
     * that we do not allow in usernames
     *
     * @param FailedLoginUser $user The user to save
     */
    private function saveLockoutFile(FailedLoginUser $user)
    {
        try {
            ShadowUser::validateName($user->getUsername());
            $this->shmConfig->saveRecord($user);
        } catch (Throwable $exception) {
            // This catch is here to suppress the creation of failedLogin files with bad names
        }
    }

    /**
     * Clear out files that haven't been modified in the last 5 minutes
     * Any lockouts in these files will have expired.
     */
    private function clearOutdatedLockoutFiles()
    {
        $files = $this->filesystem->glob(self::FAILED_LOGINS_DIRECTORY . "*");
        $currentTime = $this->timeService->getTime();

        foreach ($files as $file) {
            $modified = $this->filesystem->fileMTime($file);
            $timeDiff = $currentTime - $modified;
            if ($timeDiff > self::LOCKOUT_TIMESPAN) {
                $this->filesystem->unlink($file);
            }
        }
    }

    /**
     * Get the object representing the failed login attempts of a user
     *
     * @param string $username The user to get the attempts for
     * @return FailedLoginUser
     */
    private function getFailedLoginUser(string $username)
    {
        $failedLoginUser = new FailedLoginUser($username);

        if (!empty($username)) {
            $this->shmConfig->loadRecord($failedLoginUser);
        }
        return $failedLoginUser;
    }

    /**
     * Check to see if the username has enough failed attempts to be locked out
     *
     * @param FailedLoginUser $user The user to check
     * @return bool If the user should be locked out or not
     */
    private function shouldLockout(FailedLoginUser $user): bool
    {
        $failedAttempts = count($user->getFailedAttempts());

        return $failedAttempts >= self::ATTEMPTS_BEFORE_LOCKOUT;
    }
}
