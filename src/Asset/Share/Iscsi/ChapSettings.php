<?php

namespace Datto\Asset\Share\Iscsi;

use Datto\Iscsi\IscsiTarget;
use Datto\Iscsi\UserType;
use Exception;

/**
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class ChapSettings
{
    const DEFAULT_USER = '';
    const DEFAULT_MUTUAL_USER = '';
    const DEFAULT_USER_PASSWORD = '';
    const DEFAULT_MUTUAL_USER_PASSWORD = '';

    const CHAP_DISABLED = 0;
    const CHAP_ONE_WAY = 1;
    const CHAP_MUTUAL = 2;

    const CHAP_ERROR_PREFIX = 10;
    const MUTUAL_ERROR_PREFIX = 20;


    /** @var string */
    private $targetName;

    /** @var IscsiTarget */
    private $iscsiTarget;

    /** @var string */
    private $user;

    /** @var string */
    private $mutualUser;

    /** @var string */
    private $userPassword;

    /** @var string */
    private $mutualUserPassword;

    /**
     * @param string $name
     * @param string $user
     * @param string $mutualUser
     * @param IscsiTarget $iscsiTarget
     */
    public function __construct(
        string $name,
        string $user,
        string $mutualUser,
        IscsiTarget $iscsiTarget
    ) {
        $this->user = $user;
        $this->mutualUser = $mutualUser;
        $this->iscsiTarget = $iscsiTarget;
        $this->userPassword = self::DEFAULT_USER_PASSWORD;
        $this->mutualUserPassword = self::DEFAULT_MUTUAL_USER_PASSWORD;
        $this->targetName = $this->iscsiTarget->makeTargetName($name, '');
    }

    /**
     * @return int
     */
    public function getAuthentication()
    {
        $authentication = self::CHAP_DISABLED;

        // Can't use method return value in write context - this is fixed in 5.4 so we can change later to getters
        if (!empty($this->mutualUser)) {
            $authentication = self::CHAP_MUTUAL;
        } elseif (!empty($this->user)) {
            $authentication = self::CHAP_ONE_WAY;
        }

        return $authentication;
    }

    /**
     * Enable chap authentication.  Note that this also handles the adding/removal of a mutual
     * user if they are added/removed.
     *
     * @param string $username
     * @param string $password
     * @param boolean $enableMutual
     * @param string $mutualUsername
     * @param string $mutualPassword
     */
    public function enable($username, $password, $enableMutual, $mutualUsername, $mutualPassword): void
    {
        $existingUser = $this->getUser();
        $existingUserPassword = $this->getUserPassword();
        $existingMutualUser = $this->getMutualUser();
        $existingMutualUserPassword = $this->getMutualUserPassword();

        if ($enableMutual && $this->checkUser($mutualUsername, $mutualPassword, $existingMutualUser, self::MUTUAL_ERROR_PREFIX)) {
            if ($mutualPassword === '') {
                $mutualPassword = $existingMutualUserPassword;
            }

            if ($existingMutualUser !== '') {
                $this->removeMutualUser($existingMutualUser);
            }

            $this->addMutualUser($mutualUsername, $mutualPassword);
        } elseif (!$enableMutual) {
            // remove the mutual user if we have one set
            $mutualUser = $this->getMutualUser();

            if ($mutualUser !== self::DEFAULT_MUTUAL_USER) {
                $this->removeMutualUser($mutualUser);
            }
        }

        if ($username !== self::DEFAULT_USER && $this->checkUser($username, $password, $existingUser, self::CHAP_ERROR_PREFIX)) {
            if ($password === '') {
                $password = $existingUserPassword;
            }

            if ($existingUser !== '') {
                $this->removeUser($existingUser);
            }

            $this->addUser($username, $password);
        }
    }

    /**
     * @param string $user
     * @param string $pass
     */
    public function addUser($user, $pass): void
    {
        $this->user = $user;
        $this->iscsiTarget->addTargetChapUser($this->targetName, UserType::INCOMING(), $user, $pass);
        $this->sync();
    }

    /**
     * @return string
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @return string
     */
    public function getUserPassword()
    {
        if (empty($this->userPassword)) {
            $this->userPassword = $this->iscsiTarget->getTargetChapPassword($this->targetName);
        }
        return $this->userPassword;
    }

    /**
     * @param string $user
     */
    public function removeUser($user): void
    {
        $this->user = '';
        $this->iscsiTarget->removeTargetChapUser($this->targetName, UserType::INCOMING(), $user);
        $this->sync();
    }

    /**
     * @param string $user
     * @param string $pass
     */
    public function addMutualUser($user, $pass): void
    {
        $this->mutualUser = $user;
        $this->iscsiTarget->addTargetChapUser($this->targetName, UserType::OUTGOING(), $user, $pass);
        $this->sync();
    }

    /**
     * @return string
     */
    public function getMutualUser()
    {
        return $this->mutualUser;
    }

    /**
     * @return string
     */
    public function getMutualUserPassword()
    {
        if (empty($this->mutualUserPassword)) {
            $this->mutualUserPassword = $this->iscsiTarget->getTargetMutualChapPassword($this->targetName);
        }
        return $this->mutualUserPassword;
    }

    /**
     * @param string $user
     */
    public function removeMutualUser($user): void
    {
        $this->mutualUser = '';
        $this->iscsiTarget->removeTargetChapUser($this->targetName, UserType::OUTGOING(), $user);
        $this->sync();
    }

    /**
     * @param ChapSettings $from
     */
    public function copyFrom(ChapSettings $from): void
    {
        $authentication = $from->getAuthentication();

        if ($authentication === self::CHAP_ONE_WAY) {
            $this->enable($from->getUser(), $from->getUserPassword(), false, null, null);
        } elseif ($authentication === self::CHAP_MUTUAL) {
            $this->enable(
                $from->getUser(),
                $from->getUserPassword(),
                true,
                $from->getMutualUser(),
                $from->getMutualUserPassword()
            );
        } else {
            // remove current user and mutual user
            $currentUser = $this->getUser();
            if ($currentUser !== self::DEFAULT_USER) {
                $this->removeUser($currentUser);
            }

            $currentMutualUser = $this->getMutualUser();
            if ($currentMutualUser !== self::DEFAULT_MUTUAL_USER) {
                $this->removeMutualUser($currentMutualUser);
            }
        }
    }

    /**
     * Syncs changes made by CHAP manager
     */
    private function sync(): void
    {
        $this->iscsiTarget->writeChanges();
    }

    private function checkUser($username, $password, $existingUsername, $errorPrefix): bool
    {
        // nothing to see here so don't do anything
        if ($username === $existingUsername && $password === '') {
            return false;
        }

        if ($username === '') {
            throw new Exception('Username cannot be blank.', $errorPrefix+3);
        }

        // looks like we are just changing the username - allow
        if ($existingUsername !== '' && $password === '') {
            return true;
        }

        if ($password === '' && $existingUsername === '') {
            throw new Exception('Cannot set blank password for new user.', $errorPrefix+1);
        }

        if (strlen($password) < 12 || strlen($password) > 16) {
            throw new Exception('Password must be between 12 and 16 characters in length.', $errorPrefix+2);
        }

        return true;
    }
}
