<?php

namespace Datto\Asset\Share\Nas;

use Datto\Afp\AfpVolumeManager;
use Datto\Asset\Share\ShareException;
use Datto\Log\DeviceLoggerInterface;
use Datto\Samba\SambaManager;
use Throwable;

/**
 * Manages the Apple Filing Protocol (AFP) settings and service for a
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
class AfpSettings extends AbstractSettings
{
    const DEFAULT_ENABLED = false;

    private AfpVolumeManager $manager;
    private bool $enabled;
    private DeviceLoggerInterface $logger;

    public function __construct(
        string $name,
        DeviceLoggerInterface $logger,
        SambaManager $samba,
        AfpVolumeManager $manager,
        bool $enabled = self::DEFAULT_ENABLED
    ) {
        parent::__construct($name, $samba);

        $this->logger = $logger;
        $this->manager = $manager;
        $this->enabled = $enabled;
    }

    /**
     * Returns whether or not AFP is enabled for this share
     *
     * @return bool True if enabled, false otherwise
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * Enables AFP for the share, and add all currently enabled
     * Samba users to it. This method will restart the 'netatalk' service
     * to enable the changes.
     *
     */
    public function enable(): void
    {
        $this->logger->debug('AFP0001 Setting AFP to enabled for share');

        $sambaShare = $this->getSambaShare();
        $users = $sambaShare->getAllUsers();
        $hasUsers = count($users) > 0;

        if ($hasUsers) {
            $allowTimeMachine = true;
            $implodedUsers = implode(' ', $users);

            try {
                $this->manager->addShare(
                    $this->mountPath,
                    $this->name,
                    $allowTimeMachine,
                    $implodedUsers
                );
            } catch (Throwable $e) {
                throw new ShareException('Cannot enable AFP.', 0, $e);
            }
        }

        $this->enabled = true;
    }

    /**
     * Disable AFP for the share, and removes all Samba users from it.
     * This method will restart the 'netatalk' service to enable the changes.
     *
     */
    public function disable(): void
    {
        $this->logger->debug('AFP0002 Setting AFP to disabled for share');

        $share = $this->manager->getSharePath($this->name);

        if ($share) {
            try {
                $this->manager->removeShare($this->name);
            } catch (Throwable $e) {
                throw new ShareException('Cannot disable AFP.');
            }
        }

        $this->enabled = false;
    }

    /**
     * Change the list of users who can access the share via AFP.
     * @param string[] $users List of users
     */
    public function setUsers(array $users): void
    {
        $implodedUsernames = implode(' ', $users);
        $this->manager->changeAllowedUsers($this->name, $implodedUsernames);
    }

    /**
     * Copies the configuration from another instance of this class,
     * and applies the changes.
     *
     * @param AfpSettings $from Instance to copy the settings from
     */
    public function copyFrom(AfpSettings $from): void
    {
        if ($from->isEnabled()) {
            $this->enable();
        } else {
            $this->disable();
        }
    }
}
