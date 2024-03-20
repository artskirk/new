<?php

namespace Datto\User;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * User authentication based on the local unix users. This class
 * uses the /etc/shadow file to verify passwords/users.
 *
 * @author   Philipp Heckel <ph@datto.com>
 */
class ShadowUser
{
    /** @var Filesystem */
    private $filesystem;

    private ProcessFactory $processFactory;

    public function __construct(Filesystem $filesystem = null, ProcessFactory $processFactory = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->processFactory = $processFactory ?: new ProcessFactory();
    }

    /**
     * Reads the /etc/shadow file and returns an array of valid users if possible.
     *
     * @param bool $includeLocked if true, also add locked usernames to the result
     *
     * @return string[]|null
     */
    private function loadUsers(bool $includeLocked = false) : ?array
    {
        $shadowFileContents = $this->filesystem->fileGetContents("/etc/shadow");

        if (!$shadowFileContents) {
            return null;
        }

        $shadowLines = explode("\n", $shadowFileContents);
        $shadowUsers = [];

        foreach ($shadowLines as $shadowLine) {
            $shadowUser = $this->parseShadowLine($shadowLine, $includeLocked);

            if ($shadowUser) {
                $shadowUsers[$shadowUser['uid']] = $shadowUser;
            }
        }
        return $shadowUsers;
    }

    /**
     * Check if a user exists
     *
     * @param string $uid the username
     * @param bool $includeLocked if true, also check locked usernames
     *
     * @return boolean
     */
    public function exists($uid, bool $includeLocked = false): bool
    {
        $shadowUsers = $this->loadUsers($includeLocked);
        return is_string($uid) && isset($shadowUsers[$uid]);
    }

    /**
     * Sets the password in etc/shadow using chpasswd
     *
     * @param string $user the user whose password is being changed
     * @param string $pass the password to change to
     */
    public function setUserPass(string $user, string $pass)
    {
        $this->validateName($user);

        $process = $this->processFactory->get(['chpasswd'])->setInput("$user:$pass");
        $process->mustRun();
    }

    /**
     * Create a new Linux user using the 'useradd' command, and set
     * the given password afterwards.
     *
     * @param string $user Username
     * @param string $pass Password
     */
    public function create(string $user, string $pass)
    {
        $this->validateName($user);

        $process = $this->processFactory->get(['/usr/sbin/useradd', '-g', 'users', '-s', '/bin/false', $user]);
        $process->mustRun();

        $this->setUserPass($user, $pass);
    }

    /**
     * Delete the specified user.
     *
     * @see UnixUserService::delete()
     *
     * @param string $username
     */
    public function delete(string $username)
    {
        if ($this->exists($username)) {
            $process = $this->processFactory->get(['userdel', $username]);
            $process->mustRun();
        }
    }

    /**
     * Validates the given user name according to the device requirements,
     * and throws an exception in case it does not match.
     *
     * @param string $user Username
     */
    public static function validateName(string $user)
    {
        if (empty($user)) {
            throw new \Exception("Username cannot be empty.");
        }

        if (preg_match("/^[a-z_][a-z0-9_-]*[$]?$/i", $user) == false) {
            throw new \Exception("Username contains invalid characters. Only alphanumeric characters, '-', and '_' are valid and the name must begin with a letter.");
        }

        if (strlen($user) > 64) {
            throw new \Exception("Username length cannot exceed 64 characters.");
        }

        if (strtolower($user) === 'administrator') {
            throw new \Exception("The 'administrator' username is reserved for GUI management. Choose another username.");
        }

        if (strtolower($user) === 'root') {
            throw new \Exception("The 'root' username is reserved. Choose another username.");
        }
    }

    /**
     * Parses a single line from the /etc/shadow file and returns
     * either a user array or false if the line is invalid.
     * If includeLocked is true, users that can't log in will return
     * data, otherwise they will also return false.
     *
     * @param string $shadowLine
     * @param bool $includeLocked
     * @return array|bool
     */
    private function parseShadowLine(string $shadowLine, bool $includeLocked = false)
    {
        $shadowLineParts = explode(":", $shadowLine);

        if (count($shadowLineParts) != 9 || strlen($shadowLineParts[1]) == 0) {
            return false;
        }
        $passEntry = $shadowLineParts[1];
        $passLocked = ($passEntry[0] === '*' || $passEntry[0] === '!');

        if ($passLocked && $includeLocked) {
            return array(
                "uid" => $shadowLineParts[0]
            );
        }

        // Valid passwords in /etc/shadow should consist of at least five parts, delineated by '$'
        if (strlen($passEntry) < 5 || count(explode("$", $passEntry)) !== 4) {
            return false;
        }

        return array(
            "uid" => $shadowLineParts[0]
        );
    }
}
