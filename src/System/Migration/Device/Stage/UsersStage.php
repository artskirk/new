<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Asset\Share\Nas\NasShare;
use Datto\System\Api\DeviceApiClientService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\System\Ssh\SshClient;
use Datto\User\PasswordFileRepositoryFactory;
use Datto\User\ShadowFileRepositoryFactory;
use Datto\User\UnixUserService;
use Datto\Common\Utility\Filesystem;
use Datto\Config\AgentConfigFactory;
use Datto\Asset\Share\ShareService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Asset\AssetType;
use Datto\Samba\UserService as SambaUserService;
use Datto\User\WebUserService;
use Exception;

/**
 * Migrate all users
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class UsersStage extends AbstractMigrationStage
{
    const LINUX_PASSWD_FILE = PasswordFileRepositoryFactory::LINUX_PASSWD_FILE;
    const LINUX_SHADOW_FILE = ShadowFileRepositoryFactory::LINUX_SHADOW_FILE;
    const WEBACCESS_FILE = '/datto/config/local/webaccess';
    const SAMBA_PASSWD_FILE = '/var/lib/samba/private/passdb.tdb';

    const IMPORT_SUFFIX = '.import';
    const ADJUSTED_SUFFIX = '.adjusted';
    const BACKUP_SUFFIX = '.bak';
    const LINUX_PASSWD_IMPORT_FILE = self::LINUX_PASSWD_FILE . self::IMPORT_SUFFIX;
    const LINUX_SHADOW_IMPORT_FILE = self::LINUX_SHADOW_FILE . self::IMPORT_SUFFIX;
    const WEBACESS_IMPORT_FILE = self::WEBACCESS_FILE . self::IMPORT_SUFFIX;
    const SAMBA_PASSWD_IMPORT_FILE = self::SAMBA_PASSWD_FILE . self::IMPORT_SUFFIX;

    const SAMBA_PASSWD_ADJUSTED_FILE = self::SAMBA_PASSWD_FILE . self::ADJUSTED_SUFFIX;

    const LINUX_PASSWD_BACKUP_FILE = self::LINUX_PASSWD_FILE . self::BACKUP_SUFFIX;
    const LINUX_SHADOW_BACKUP_FILE = self::LINUX_SHADOW_FILE . self::BACKUP_SUFFIX;
    const WEBACESS_BACKUP_FILE = self::WEBACCESS_FILE . self::BACKUP_SUFFIX;
    const SAMBA_PASSWD_BACKUP_FILE = self::SAMBA_PASSWD_FILE . self::BACKUP_SUFFIX;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DeviceApiClientService */
    private $deviceClient;

    /** @var UnixUserService */
    private $unixUserService;

    /** @var SshClient */
    private $sshClient;

    /** @var Filesystem */
    private $filesystem;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var ShareService */
    private $shareService;

    /** @var SambaUserService */
    private $sambaUserService;

    /** @var WebUserService */
    private $webUserService;

    /**
     * @param Context $context
     * @param DeviceLoggerInterface $logger
     * @param DeviceApiClientService $deviceClient
     * @param UnixUserService $unixUserService
     * @param SshClient $sshClient
     * @param Filesystem $filesystem
     * @param AgentConfigFactory $agentConfigFactory
     * @param ShareService $shareService
     * @param SambaUserService $sambaUserService
     * @param WebUserService $webUserService
     */
    public function __construct(
        Context $context,
        DeviceLoggerInterface $logger,
        DeviceApiClientService $deviceClient,
        UnixUserService $unixUserService,
        SshClient $sshClient,
        Filesystem $filesystem,
        AgentConfigFactory $agentConfigFactory,
        ShareService $shareService,
        SambaUserService $sambaUserService,
        WebUserService $webUserService
    ) {
        parent::__construct($context);
        $this->logger = $logger;
        $this->deviceClient = $deviceClient;
        $this->unixUserService = $unixUserService;
        $this->sshClient = $sshClient;
        $this->filesystem = $filesystem;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->shareService = $shareService;
        $this->sambaUserService = $sambaUserService;
        $this->webUserService = $webUserService;
    }

    /**
     * @inheritdoc
     *
     * This is designed so that all SSH file transfers are done to temporary
     * files first.  If any transfer fails, an exception will occur and nothing
     * will be changed.  In addition, if an exception occurs while importing
     * the Linux users (e.g. uid conflict), nothing will be changed.
     */
    public function commit()
    {
        $this->initAllFiles();
        $targets = $this->context->getTargets();
        // A full migration moves all configuration to a new empty device.
        $fullMigration = in_array(DeviceConfigStage::DEVICE_TARGET, $targets);

        if ($fullMigration) {
            $this->unixUserService->importNormalUsersFromFile(
                self::LINUX_PASSWD_IMPORT_FILE,
                self::LINUX_SHADOW_IMPORT_FILE
            );

            if (!$this->filesystem->rename(self::WEBACESS_IMPORT_FILE, self::WEBACCESS_FILE)) {
                throw new Exception('Error installing migrated web access file');
            }
            if (!$this->filesystem->rename(self::SAMBA_PASSWD_IMPORT_FILE, self::SAMBA_PASSWD_FILE)) {
                throw new Exception('Error installing migrated samba password file');
            }
        } else {
            $remoteShareUsers = $this->getAllRemoteShareUsers();
            $this->updateUsers($remoteShareUsers);
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        $filesToCleanup = [
            self::LINUX_PASSWD_IMPORT_FILE,
            self::LINUX_SHADOW_IMPORT_FILE,
            self::WEBACESS_IMPORT_FILE,
            self::SAMBA_PASSWD_IMPORT_FILE,
            self::SAMBA_PASSWD_ADJUSTED_FILE,
            self::LINUX_SHADOW_BACKUP_FILE,
            self::WEBACESS_BACKUP_FILE,
            self::SAMBA_PASSWD_BACKUP_FILE
        ];
        foreach ($filesToCleanup as $fileToCleanup) {
            if ($this->filesystem->exists($fileToCleanup)) {
                $this->filesystem->unlink($fileToCleanup);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        $this->cleanup();
    }

    /**
     * @return array
     */
    private function getAllRemoteShareUsers()
    {
        $users = [];
        foreach ($this->context->getTargets() as $target) {
            $agentConfig = $this->agentConfigFactory->create($target);
            if (!$agentConfig->isShare()) {
                 continue;
            }
            $share = $this->shareService->get($target);
            if ($share instanceof NasShare) {
                /** @var NasShare $share */
                $shareUsers = $share->getUsers()->getAll();
                $users = array_merge($users, is_array($shareUsers) ? $shareUsers : []);
            }
        }
        return array_unique($users);
    }

    /**
     * @param array $users
     */
    private function updateUsers(array $users)
    {
        $sambaUsersToMigrrate = [];
        foreach ($users as $user) {
            //make copies of
            $linuxUser = $this->isLinuxUser($user);
            $webaccessUser = $this->isWebaccessUser($user);
            $sambaDatabaseUser = $this->isSambaDatabaseUser($user);
            $needsAll = !$linuxUser && !$webaccessUser && !$sambaDatabaseUser;
            $corruption = (!$linuxUser && $sambaDatabaseUser) || (!$linuxUser && $webaccessUser);
            $nameCollision = $linuxUser && !$webaccessUser;
            $needsSambaUser = $linuxUser && $webaccessUser && !$sambaDatabaseUser;
            if ($needsAll) {
                //brand new user
                $this->migrateLinuxAndDatto($user);
                $sambaUsersToMigrrate[] = $this->unixUserService->getPasswdEntry($user);
            } elseif ($corruption) {
                //samba or web access user is not a linux user implies corrupt system
                $message = "User $user is a local Samba user but not a linux user. Please contact support for assitance.";
                throw new Exception("MIG1000 $message");
            } elseif ($nameCollision) {
                //linux user is not a datto user this is probably a name collision
                $message = "User $user is a Linux user but not a datto user. This is probably a name collision.  Please contact support for assitance.";
                throw new Exception("MIG1000 $message");
            } elseif ($needsSambaUser) {
                //addSamba user
                $sambaUsersToMigrrate[] = $this->unixUserService->getPasswdEntry($user);
            } else {
                // User already exists and is a sambauser.  No need to do anything
            }
        }
        $this->sambaUserService->importSambaUsersToSambaDb(
            $sambaUsersToMigrrate,
            self::SAMBA_PASSWD_IMPORT_FILE,
            self::SAMBA_PASSWD_ADJUSTED_FILE
        );
    }

    /**
     *  check samba database to see if user is a sabma database user
     *
     */
    private function initAllFiles()
    {
        $this->cleanup();
        $this->sshClient->copyFromRemote(self::LINUX_PASSWD_FILE, self::LINUX_PASSWD_IMPORT_FILE);
        $this->sshClient->copyFromRemote(self::LINUX_SHADOW_FILE, self::LINUX_SHADOW_IMPORT_FILE);
        $this->sshClient->copyFromRemote(self::WEBACCESS_FILE, self::WEBACESS_IMPORT_FILE);
        $this->sshClient->copyFromRemote(self::SAMBA_PASSWD_FILE, self::SAMBA_PASSWD_IMPORT_FILE);
    }

    /**
     *  check paswd file to see if user is a linux user
     *
     * @param string $user
     * @return bool
     */
    private function isLinuxUser(string $user)
    {
         return $this->unixUserService->exists($user);
    }

    /**
     *  check webAccess file to see if user is a datto user
     *
     * @param string $user
     * @return bool
     */
    private function isWebaccessUser(string $user)
    {
        return $this->webUserService->exists($user);
    }

    /**
     *  check samba database to see if user is a sabma database user
     *
     * @param string $user
     * @return bool
     */
    private function isSambaDatabaseUser(string $user)
    {
        return in_array($user, $this->sambaUserService->getLocalUsers());
    }

    /**
     *  Adds a new linux user and web access user
     *
     * @param string $user
     */
    private function migrateLinuxAndDatto(string $user)
    {
        $this->unixUserService->addUserImportPassword($user, self::LINUX_SHADOW_IMPORT_FILE);
        $this->webUserService->addUserImportPassword($user, self::WEBACESS_IMPORT_FILE);
    }
}
