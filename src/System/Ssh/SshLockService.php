<?php

namespace Datto\System\Ssh;

use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Systemd\Systemctl;
use Datto\Utility\User\UserMod;
use Psr\Log\LoggerAwareInterface;

/**
 * Responsible for locking and unlocking SSH access to the system and ensuring SSHD is running.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class SshLockService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var string[] */
    const ALLOWED_USERS = ["root", "backup-admin"];

    /** @var string */
    const GROUP_SSH = "remote-users";

    /** @var int */
    const LOCK_STATUS_UNLOCKED = 0;

    /** @var int */
    const LOCK_STATUS_LOCKED = 1;

    /** @var int */
    const LOCK_STATUS_UNKNOWN = 2;

    /** @var int */
    /**
     * This constant uses 'ssh' rather than the alias 'sshd' because the alias
     * 'sshd' is not present after the service has been disabled on the device.
     * See 'https://unix.stackexchange.com/questions/466916/why-not-ssh-service-but-sshd-service'.
     */
    const SSHD_SERVICE_NAME = "ssh";

    /** @var string */
    /** This is a linux file the existence of which prevents SSHD from running */
    const SSHD_NO_RUN_FILE = '/etc/ssh/sshd_not_to_be_run';

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var UserMod */
    private $userMod;

    /** @var Systemctl */
    private $systemctl;

    /** @var Filesystem */
    private $fileSystem;

    /** @var DeviceState */
    private $deviceState;

    public function __construct(
        DeviceConfig $deviceConfig,
        UserMod $userMod,
        Systemctl $systemctl,
        Filesystem $filesystem,
        DeviceState $deviceState
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->userMod = $userMod;
        $this->systemctl = $systemctl;
        $this->fileSystem = $filesystem;
        $this->deviceState = $deviceState;
    }

    /**
     * Set system such that root and backup-admin cannot ssh into the system but ensure that SSHD is running.
     */
    public function lock()
    {
        $this->logger->info("SSH0007 locking SSH access to the device");

        foreach (self::ALLOWED_USERS as $user) {
            $this->userMod->removeFromGroup($user, self::GROUP_SSH);
        }
        $this->deviceConfig->set(DeviceConfig::KEY_SSH_LOCK_STATUS, json_encode(["locked" => true]));

        $this->updateSshdStatus();
    }

    /**
     * Set the system such that root and backup-admin can ssh into the system and ensure that SSHD is running.
     */
    public function unlock()
    {
        $this->logger->info("SSH0008 unlocking SSH access to the device");

        foreach (self::ALLOWED_USERS as $user) {
            $this->userMod->addToGroup($user, self::GROUP_SSH);
        }
        $this->deviceConfig->set(DeviceConfig::KEY_SSH_LOCK_STATUS, json_encode(["locked" => false]));

        $this->updateSshdStatus();
    }

    /**
     * @return int
     */
    public function lockStatus(): int
    {
        $lockStatus = json_decode(
            $this->deviceConfig->get(
                DeviceConfig::KEY_SSH_LOCK_STATUS
            ),
            true
        );

        return $lockStatus["locked"] ?? self::LOCK_STATUS_UNKNOWN;
    }

    /**
     * Updates the SSHD service depending on if P2P assets are paired and the status of the SSH 'lock'.
     */
    public function updateSshdStatus()
    {
        $speedSyncNeedsSshd = $this->deviceState->has(DeviceState::SPEED_SYNC_ACTIVE) ||
            $this->deviceConfig->isCloudDevice();

        if (!$speedSyncNeedsSshd && $this->lockStatus() === self::LOCK_STATUS_LOCKED) {
            // Make sure its stopped now.
            if ($this->systemctl->isActive(self::SSHD_SERVICE_NAME)) {
                $this->systemctl->stop(self::SSHD_SERVICE_NAME);
            }

            // Make sure it doesn't start up after rebooting
            if ($this->systemctl->isEnabled(self::SSHD_SERVICE_NAME)) {
                $this->systemctl->disable(self::SSHD_SERVICE_NAME);
            }

            // Prevent SSHD from running
            $this->fileSystem->touch(self::SSHD_NO_RUN_FILE);
        } else {
            // Remove file that prevents SSHD from running
            $this->fileSystem->unlinkIfExists(self::SSHD_NO_RUN_FILE);

            //Make sure it starts up after rebooting
            if (!$this->systemctl->isEnabled(self::SSHD_SERVICE_NAME)) {
                $this->systemctl->enable(self::SSHD_SERVICE_NAME);
            }

            //Make sure it starts now
            if (!$this->systemctl->isActive(self::SSHD_SERVICE_NAME)) {
                $this->systemctl->start(self::SSHD_SERVICE_NAME);
            }
        }
    }
}
