<?php

namespace Datto\System\Ssh;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Exception;

/**
 * Local SSHD server service used for device migrations.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class SshServer
{
    const SSH_PORT = 8022;
    const SSH_AUTHORIZED_USER = 'root';
    const AUTHORIZED_KEYS_FILE = '/root/.ssh/authorized_keys.device';
    const SSHD_PID_FILE = '/var/run/deviceSshd.pid';
    const TIMEOUT_IN_SECS = 604800;  // one week

    /** @var Filesystem */
    private $filesystem;

    private ProcessFactory $processFactory;

    /** @var PosixHelper */
    private $posixHelper;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct(
        Filesystem $filesystem,
        ProcessFactory $processFactory,
        PosixHelper $posixHelper,
        DeviceLoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->processFactory = $processFactory;
        $this->posixHelper = $posixHelper;
        $this->logger = $logger;
    }

    /**
     * Sets the authorized public key for the SSH server.
     * The key must be a valid OpenSSH rsa-ssh key.
     *
     * @param string $publicKey
     */
    public function setAuthorizedKey(string $publicKey)
    {
        if (!preg_match('#^ssh-rsa AAAA[0-9A-Za-z+/]+[=]{0,3}( |$)#', $publicKey)) {
            $message = "Invalid public key format";
            $this->logger->error("DSS0021 $message");
            throw new Exception($message);
        }
        if ($this->filesystem->filePutContents(self::AUTHORIZED_KEYS_FILE, $publicKey) === false) {
            $message = "Unable to set the authorized key";
            $this->logger->error("DSS0022 $message");
            throw new Exception($message);
        }
    }

    /**
     * Determines if an authorized key has been set.
     *
     * @return bool
     */
    public function hasAuthorizedKey(): bool
    {
        return $this->filesystem->exists(self::AUTHORIZED_KEYS_FILE);
    }

    /**
     * Stops the SSH server, if it's running, and deletes the authorized key.
     */
    public function tearDown()
    {
        $this->stop();
        if ($this->filesystem->exists(self::AUTHORIZED_KEYS_FILE)) {
            $this->filesystem->unlink(self::AUTHORIZED_KEYS_FILE);
        }
    }

    /**
     * Starts the SSH daemon.
     */
    public function start()
    {
        if (!$this->hasAuthorizedKey()) {
            $message = "Missing authorized key";
            $this->logger->error("DSS0023 $message");
            throw new Exception($message);
        }

        $this->logger->info("DSS0001 Starting the SSH server");

        if (!$this->isRunning()) {
            $authorizedKeysFile = self::AUTHORIZED_KEYS_FILE;
            $pidFile = self::SSHD_PID_FILE;
            $process = $this->processFactory
                ->get([
                    '/usr/sbin/sshd',
                    '-o', "AuthorizedKeysFile=$authorizedKeysFile",
                    '-o', "PidFile=$pidFile",
                    '-o', 'AllowUsers ' . self::SSH_AUTHORIZED_USER,
                    '-o', 'AllowGroups ' . self::SSH_AUTHORIZED_USER,
                    '-p', self::SSH_PORT
                ])
                ->setTimeout(self::TIMEOUT_IN_SECS);
            $process->run();
            $running = $this->isRunning();
            for ($i = 0; !$running && $i < 10; $i++) {
                usleep(100);
                $running = $this->isRunning();
            }
            if (!$running) {
                $message = "Unable to start the SSH server";
                $this->logger->error("DSS0010 $message");
                throw new Exception($message);
            }
            $this->logger->info("DSS0002 The SSH server started successfully");
        } else {
            $this->logger->info("DSS0003 The SSH server is already running");
        }
    }

    /**
     * Determine if SSH daemon is running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->isRunningPid($this->getProcessId());
    }

    /**
     * Kills running SSH daemon and deletes the PID file.
     */
    public function stop()
    {
        $this->logger->info("DSS0004 Stopping the SSH server");

        $pid = $this->getProcessId();
        if ($this->isRunningPid($pid)) {
            $success = $this->posixHelper->kill($pid, 9);
            if (!$success) {
                $message = "Error terminating the SSH server";
                $this->logger->error("DSS0011 $message");
                throw new Exception($message);
            }
            $running = $this->isRunning();
            for ($i = 0; $running && $i < 10; $i++) {
                usleep(100);
                $running = $this->isRunning();
            }
            if ($running) {
                $message = "Unable to stop the SSH server";
                $this->logger->error("DSS0012 $message");
                throw new Exception($message);
            }
            $this->logger->info("DSS0005 The SSH server stopped successfully");
        } else {
            $this->logger->info("DSS0006 The SSH server is already stopped");
        }
        if ($this->filesystem->exists(self::SSHD_PID_FILE)) {
            $this->filesystem->unlink(self::SSHD_PID_FILE);
        }
    }

    /**
     * Gets the process ID saved by the SSHD server.
     *
     * @return int Process ID or 0 if no valid process ID file was found.
     */
    private function getProcessId(): int
    {
        if ($this->filesystem->exists(self::SSHD_PID_FILE)) {
            $pid = intval($this->filesystem->fileGetContents(self::SSHD_PID_FILE));
            if ($pid > 1) {
                return $pid;
            }
        }
        return 0;
    }

    /**
     * Determines if a process with the given process ID is currently running.
     *
     * @param int $pid The process ID to check.
     * @return bool True if running, false if not running or $pid is 0.
     */
    private function isRunningPid(int $pid): bool
    {
        return $pid != 0 && $this->posixHelper->getProcessGroupId($pid) !== false;
    }
}
