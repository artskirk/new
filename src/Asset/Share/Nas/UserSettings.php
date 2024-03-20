<?php

namespace Datto\Asset\Share\Nas;

use Datto\Asset\Share\ShareException;
use Datto\Samba\SambaManager;

/**
 * Asset settings related to managing users for a certain NAS share.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class UserSettings extends AbstractSettings
{
    /** @var AfpSettings */
    private $afp;

    /** @var SftpSettings */
    private $sftp;

    /**
     * @param string $name
     * @param SambaManager $samba
     * @param AfpSettings $afp
     * @param SftpSettings $sftp
     */
    public function __construct($name, SambaManager $samba, AfpSettings $afp, SftpSettings $sftp)
    {
        parent::__construct($name, $samba);

        $this->afp = $afp;
        $this->sftp = $sftp;
    }

    /**
     * Returns users that are added to this share
     *
     * @return array
     */
    public function getAll()
    {
        $sambaShare = $this->getSambaShare();
        return $sambaShare->getAllUsers();
    }

    /**
     * @return array
     */
    public function getAdminUsers()
    {
        $sambaShare = $this->getSambaShare();
        return $sambaShare->getAdminUsers();
    }

    /**
     * Add a user to the share
     *
     * @param $username
     * @param bool|false $asAdmin
     */
    public function add($username, $asAdmin = false): void
    {
        $sambaShare = $this->getSambaShare();

        $added = $sambaShare->addUser($username, $asAdmin);

        if (!$added) {
            throw new ShareException('Unable to add user to share');
        }

        if ($this->afp->isEnabled()) {
            $this->afp->setUsers($sambaShare->getAllUsers());
        }

        if ($this->sftp->isEnabled()) {
            $this->sftp->addUsers(array($username));
        }

        $this->samba->sync();
    }

    /**
     * @param $group
     */
    public function addGroup($group): void
    {
        $sambaShare = $this->getSambaShare();
        $added = $sambaShare->addGroup($group);

        if (!$added) {
            throw new ShareException('Unable to add group to share');
        }

        $this->samba->sync();
    }

    /**
     * @param $group
     */
    public function removeGroup($group): void
    {
        $sambaShare = $this->getSambaShare();
        $removed = $sambaShare->removeGroup($group);

        if (!$removed) {
            throw new ShareException('Unable to remove group from share');
        }

        $this->samba->sync();
    }

    /**
     * Remove a user from the share
     *
     * @param $username
     */
    public function remove($username): void
    {
        $sambaShare = $this->getSambaShare();

        $removed = $sambaShare->removeUser($username);

        if (!$removed) {
            throw new ShareException('Unable to remove user to share');
        }

        if ($this->afp->isEnabled()) {
            $this->afp->setUsers($sambaShare->getAllUsers());
        }

        if ($this->sftp->isEnabled()) {
            $this->sftp->removeUsers(array($username));
        }

        $this->samba->sync();
    }

    /**
     * Change admin access for $username.  Works by calling remove() followed by
     * add() which also adds the user to sftp/afp.
     *
     * @param $username
     * @param $isAdmin
     */
    public function setAdmin($username, $isAdmin): void
    {
        try {
            $this->remove($username);
            $this->add($username, $isAdmin);
        } catch (ShareException $e) {
            throw new ShareException('Unable to change admin settings for user, reason: '.$e->getMessage());
        }
    }

    /**
     * Copy an existing NasShare's UserSettings
     *
     * @param UserSettings $from the UserSettings from an existing NasShare to be copied
     */
    public function copyFrom(UserSettings $from): void
    {
        $sambaUsers = $from->getAll();
        $adminUsers = $from->getAdminUsers();

        $sambaShare = $from->getSambaShare();
        $groups = $sambaShare->getAllGroups();
        $regularUsers = array_diff($sambaUsers, $groups, $adminUsers);

        foreach ($regularUsers as $user) {
            $this->add($user, false);
        }
        foreach ($adminUsers as $user) {
            $this->add($user, true);
        }
        foreach ($groups as $group) {
            $this->addGroup($group);
        }
    }
}
