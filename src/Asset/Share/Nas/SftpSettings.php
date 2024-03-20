<?php

namespace Datto\Asset\Share\Nas;

use Datto\Samba\SambaManager;
use Datto\Sftp\SftpManager;

/**
 * Manages the Secure File Transfer Protocol (SFTP) settings and service for a
 * specific share.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class SftpSettings extends AbstractSettings
{
    const DEFAULT_ENABLED = false;

    /** @var SftpManager */
    private $manager;

    /** @var bool */
    private $enabled;

    /**
     * Create an instance of this settings class
     *
     * @param string $name
     * @param SambaManager $sambaManager
     * @param SftpManager $sftpManager
     * @param bool $enabled
     */
    public function __construct(
        $name,
        SambaManager $sambaManager,
        SftpManager $sftpManager = null,
        $enabled = self::DEFAULT_ENABLED
    ) {
        parent::__construct($name, $sambaManager);

        $this->manager = $sftpManager ?: new SftpManager();
        $this->enabled = $enabled;
    }

    /**
     * Returns whether or not SFTP is enabled for this share
     *
     * @return bool True if enabled, false otherwise
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * Enables SFTP for the share, and add all currently enabled
     * Samba users to it.
     *
     * If the underlying Samba share is public, SFTP access will be granted for the
     * 'anonymous' user. If the underlying Samba share is not public, it will only grant
     * access to these users.
     *
     * Under the hood, this will create a 'mount --bind' in the home folder of the
     * allowed users.
     */
    public function enable(): void
    {
        $sambaShare = $this->getSambaShare();
        $usernames = $sambaShare->getAllUsers();

        $this->addUsers($usernames);
        $this->manager->startIfUsers();

        $this->enabled = true;
    }

    /**
     * Disable SFTP for the share, and removes all Samba
     * users from it.
     */
    public function disable(): void
    {
        $sambaShare = $this->getSambaShare();
        $usernames = $sambaShare->getAllUsers();

        $this->removeUsers($usernames);
        $this->manager->stopIfNoUsers();

        $this->enabled = false;
    }

    /**
     * Copies the configuration from another instance of this class,
     * and applies the changes.
     *
     * @param SftpSettings $from Instance to copy the settings from
     */
    public function copyFrom(SftpSettings $from): void
    {
        if ($from->isEnabled()) {
            $this->enable();
        } else {
            $this->disable();
        }
    }

    /**
     * @param array $usernames
     */
    public function addUsers(array $usernames): void
    {
        $sambaShare = $this->getSambaShare();

        foreach ($usernames as $username) {
            if ($this->manager->mountExists($username, $this->name)) {
                continue;
            }

            $this->manager->mount($username, $this->name, $sambaShare->getPath());
        }
    }

    /**
     * @param array $usernames
     */
    public function removeUsers(array $usernames): void
    {
        foreach ($usernames as $username) {
            if (!$this->manager->mountExists($username, $this->name)) {
                continue;
            }

            $this->manager->unmount($username, $this->name);
        }
    }
}
