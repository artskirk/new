<?php

namespace Datto\Sftp;

use Datto\AppKernel;
use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerFactory;
use Datto\Service\Security\FirewallService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\PosixHelper;
use Datto\System\AbstractMountBindManager;
use Datto\System\MountManager;
use Datto\Utility\Process\Ps;
use Datto\Utility\Systemd\Systemctl;

class SftpManager extends AbstractMountBindManager
{
    const DEFAULT_SFTP_DIR = '/var/sftp';
    const SFTP_DTC_ACCESSIBLE_PERMISSIONS = 0555;// Prevents write access to sftp mount point

    private FirewallService $firewallService;

    private PosixHelper $posixHelper;

    private Ps $ps;
    
    private Systemctl $systemctl;

    public function __construct(
        string $rootDir = self::DEFAULT_SFTP_DIR,
        LoggerFactory $loggerFactory = null,
        Filesystem $filesystem = null,
        ProcessFactory $processFactory = null,
        MountManager $mountManager = null,
        FirewallService $firewallService = null,
        PosixHelper $posixHelper = null,
        Ps $ps = null,
        Systemctl $systemctl = null
    ) {
        parent::__construct($rootDir, $loggerFactory, $filesystem, $processFactory, $mountManager);

        $this->firewallService = $firewallService ??
            AppKernel::getBootedInstance()->getContainer()->get(FirewallService::class);
        $this->posixHelper = $posixHelper ?: new PosixHelper($this->processFactory);
        $this->ps = $ps ?: new Ps();
        $this->systemctl = $systemctl ?? new Systemctl($processFactory);
    }

    /**
     * Mounts a new directory for the specified point for the target user.
     * The mount point does not have write access.
     *
     * @param string $username
     * @param string $asset
     * @param string $source
     */
    public function restrictedMount(string $username, string $asset, string $source): void
    {
        $logger = $this->loggerFactory->getAsset($asset);
        parent::mount($username, $asset, $source);
        $userDir = $this->getUserDirectory($username);
        $mountPoint = $userDir . '/' . $asset;
        $logger->info('BND0006 Removing write access to mount point', ['mountPoint' => $mountPoint]);
        @$this->filesystem->chmod($mountPoint, self::SFTP_DTC_ACCESSIBLE_PERMISSIONS);
    }

    public function startIfUsers(): void
    {
        if (count($this->getUserDirs()) > 0) {
            $logger = $this->loggerFactory->getDevice();
            $logger->info('BND0003 At least one SFTP user mount, starting ssh-sftponly service ...');
            $this->firewallService->enableSftp(true);
            $this->systemctl->start('ssh-sftponly');
        }
    }

    public function stopIfNoUsers(): void
    {
        if (count($this->getUserDirs()) === 0) {
            $logger = $this->loggerFactory->getDevice();
            $logger->info('BND0004 No SFTP user mounts found, stopping ssh-sftponly service ...');
            $this->systemctl->stop('ssh-sftponly');
            $this->firewallService->enableSftp(false);
        }
    }

    /**
     * Kill any active processes for the specified user.
     *
     * @param string $username
     */
    public function killConnections(string $username): void
    {
        $pids = $this->ps->getPidsFromCommandPattern("/sshd:\s+$username/");
        $logger = $this->loggerFactory->getDevice();
        $logger->info('BND0005 Killing processes for user', ['processCount' => count($pids), 'username' => $username]);

        foreach ($pids as $pid) {
            $this->posixHelper->kill($pid, PosixHelper::SIGNAL_KILL);
        }
    }

    private function getUserDirs(): array
    {
        $res = $this->filesystem->glob($this->rootDir . '/*');
        if ($res === false) {
            $res = [];
        }
        return $res;
    }
}
