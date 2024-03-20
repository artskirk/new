<?php

namespace Datto\Iscsi;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\DmCryptManager;
use Datto\Block\LoopInfo;
use Datto\Block\LoopManager;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\ShmConfig;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\RestoreType;
use Datto\Util\NetworkSystem;
use Datto\Utility\Block\Losetup;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Utility\File\LockInfo;
use Datto\Utility\Iscsi\Targetcli;
use Datto\Utility\Iscsi\Targetctl;
use Datto\Log\DeviceLoggerInterface;
use RuntimeException;
use Exception;

/**
 * Manage LIO iSCSI targets
 *
 * This uses a combination of `targetcli` and directly manipulating configfs.
 *
 * The following assumptions have been made in this implementation:
 *  * targets have only one TPG
 *  * only a single user is allowed for each type (defined in the UserType enumeration)
 *    - adding support for multiple users would require ACL-level authentication
 *  * LUN IDs are auto-assigned
 *  * all targets use the default NOP ping interval (15s) and timeout (30s)
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class IscsiTarget
{
    const CONFIGFS_ISCSI_PATH = '/sys/kernel/config/target/iscsi/';
    const CONFIGFS_BACKSTORE_PATH = '/sys/kernel/config/target/core/';
    const LIO_CONFIG_FILE = '/etc/rtslib-fb-target/saveconfig.json';
    const LIO_CONFIG_RESTORED_KEY = 'lioConfigRestored';

    const AUTH_PARAM_USER_ID = 'userid';
    const AUTH_PARAM_PASSWORD = 'password';
    const AUTH_PARAM_USER_ID_MUTUAL = 'mutual_userid';
    const AUTH_PARAM_PASSWORD_MUTUAL = 'mutual_password';

    const ACCESS_TYPE_BLOCK = 'block';
    const ACCESS_TYPE_FILEIO = 'fileio';

    /** @var DmCryptManager */
    private $dmCryptManager;

    /** @var Filesystem */
    private $filesystem;

    /** @var LoopManager */
    private $loopManager;

    /** @var NetworkSystem */
    private $network;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Targetcli */
    private $targetcli;

    /** @var Losetup */
    private $losetup;

    /** @var Lock */
    private $configFSLock;

    /** @var ShmConfig */
    private $shmConfig;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var Targetctl */
    private $targetctl;

    /**
     * @param Filesystem|null $filesystem
     * @param NetworkSystem|null $network
     * @param DmCryptManager|null $dmCryptManager
     * @param LoopManager|null $loopManager
     * @param DeviceLoggerInterface|null $logger
     * @param ProcessFactory|null $processFactory
     * @param Targetcli|null $targetcli
     * @param Losetup|null $losetup
     * @param LockFactory|null $lockFactory
     * @param ShmConfig|null $shmConfig
     * @param DateTimeService|null $dateTimeService
     * @param Targetctl|null $targetctl
     */
    public function __construct(
        Filesystem $filesystem = null,
        NetworkSystem $network = null,
        DmCryptManager $dmCryptManager = null,
        LoopManager $loopManager = null,
        DeviceLoggerInterface $logger = null,
        ProcessFactory $processFactory = null,
        Targetcli $targetcli = null,
        Losetup $losetup = null,
        LockFactory $lockFactory = null,
        ShmConfig $shmConfig = null,
        DateTimeService $dateTimeService = null,
        Targetctl $targetctl = null
    ) {
        $processFactory = $processFactory ?? new ProcessFactory();

        $this->filesystem = $filesystem ?? new Filesystem($processFactory);
        $this->network = $network ?? new NetworkSystem();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->loopManager = $loopManager ?? new LoopManager($this->logger, $this->filesystem);
        $this->dmCryptManager = $dmCryptManager ??
            new DmCryptManager(
                $this->filesystem,
                null,
                $this->loopManager,
                null,
                $this->logger
            );
        $this->losetup = $losetup ?? new Losetup($processFactory, $this->filesystem);
        $lockFactory = $lockFactory ?? new LockFactory($this->filesystem);
        $this->configFSLock = $lockFactory->getProcessScopedLock(LockInfo::CONFIGFS_LOCK_PATH);
        $this->targetcli = $targetcli ?? new Targetcli(
            $processFactory,
            $this->filesystem,
            $lockFactory
        );
        $this->shmConfig = $shmConfig ?? new ShmConfig($this->filesystem);
        $this->dateTimeService = $dateTimeService ?? new DateTimeService();
        $this->targetctl = $targetctl ?? new Targetctl(
            $processFactory,
            $lockFactory
        );
    }

    /**
     * Safely return if a target exists
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @return bool TRUE if the target exists
     */
    public function doesTargetExist(string $target): bool
    {
        try {
            return $this->checkTargetExistence($target);
        } catch (IscsiTargetNotFoundException $e) {
            return false;
        }
    }

    /**
     * Make an iSCSI Qualified Name for a target out of an agent name
     *
     * @param string $agentHostname hostname of the agent
     * @param string $prefix prefix to use for the target name
     * @return string iSCSI Qualified Name (IQN) for the target
     */
    public function makeTargetName(string $agentHostname, string $prefix = 'agent'): string
    {
        $targetName = sprintf(
            'iqn.%s.net.datto.dev.%s:%s%s',
            '2007-01', // date('Y-m')
            strtolower($this->network->getHostName()),
            $prefix,
            strtolower($agentHostname)
        );

        // Per RFC-3722, underscores are not allowed in target names. Before LIO we used the less strict open-iscsi
        // which allowed underscores. We must replace them here so targets continue to work.
        return str_replace('_', '-', $targetName);
    }

    /**
     * Make an iSCSI Qualified Name for a target out of an agent name
     *
     * This target will not be saved to the config file.
     *
     * @param string $agentHostname hostname of the agent
     * @param string $prefix prefix to use for the target name
     * @return string iSCSI Qualified Name (IQN) for the target
     */
    public function makeTargetNameTemp(string $agentHostname, string $prefix = 'agent'): string
    {
        $targetName = sprintf(
            'iqn.%s.net.datto.dev.temp.%s:%s%s',
            '2007-01', // date('Y-m')
            strtolower($this->network->getHostName()),
            $prefix,
            strtolower($agentHostname)
        );

        // Per RFC-3722, underscores are not allowed in target names. Before LIO we used the less strict open-iscsi
        // which allowed underscores. We must replace them here so targets continue to work.
        return str_replace('_', '-', $targetName);
    }

    /**
     * List all targets
     *
     * @return string[] iSCSI Qualified Names (IQNs) of all targets
     */
    public function listTargets(): array
    {
        $configfsTargets = $this->filesystem->glob(self:: CONFIGFS_ISCSI_PATH . 'iqn.*', GLOB_ONLYDIR);
        if ($configfsTargets === false) {
            throw new IscsiTargetException('Failed to list LIO iSCSI targets from configfs');
        }

        return array_map('basename', $configfsTargets);
    }

    /**
     * Get a list of targets associated with a given path
     *
     * @param string $path the path to a file or block device
     * @return string[] iSCSI Qualified Names (IQNs) of targets associated with the provided path
     */
    public function getTargetsByPath(string $path): array
    {
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $lunUdevPaths = $this->filesystem->glob(self::CONFIGFS_ISCSI_PATH . 'iqn*/tpgt_1/lun/lun_*/*/udev_path');
            $targetRegex = '%^' . self::CONFIGFS_ISCSI_PATH . '(?P<target>iqn[^/]+)%';

            // Include dm-crypt when searching for targets.
            // Use both the /dev/dm-* and /dev/mapper/*-crypt-* paths.
            $devCryptDevices = $this->dmCryptManager->getDMCryptDevicesForFile($path, true);
            $devMapperCryptDevices = array_map(function (string $dmDeviceName) {
                return '/dev/mapper/' . $dmDeviceName;
            }, $this->dmCryptManager->getDMCryptDevicesForFile($path));

            // Include loop devices when searching for targets associated with the path.
            $loopDevices = array_map(function (LoopInfo $loopDevice) {
                return $loopDevice->getPath();
            }, $this->loopManager->getLoopsOnFile($path));

            $searchPaths = array_merge([$path], $devCryptDevices, $devMapperCryptDevices, $loopDevices);

            $targets = [];
            foreach ($lunUdevPaths as $udevPath) {
                if (!in_array(trim($this->filesystem->fileGetContents($udevPath)), $searchPaths, true)) {
                    continue;
                }

                if (preg_match($targetRegex, $udevPath, $matches) &&
                    !in_array($matches['target'], $targets, true)) {
                    $targets[] = $matches['target'];
                }
            }
        } finally {
            $this->configFSLock->unlock();
        }

        return $targets;
    }

    /**
     * Add a CHAP user to an iSCSI target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @param UserType $userType the type of user that will be added
     * @param string $user the username that will be added
     * @param string $pass the password that will be added
     * @param bool $closeSessions whether or not sessions on the existing target should be closed
     */
    public function addTargetChapUser(
        string $target,
        UserType $userType,
        string $user,
        string $pass,
        bool $closeSessions = true
    ) {
        $targetUsers = $this->listTargetChapUsers($target);

        if (isset($targetUsers[$userType->value()])) {
            throw new IscsiTargetException(
                'A target user already exists. Delete the existing ' . $userType->value() .
                ' in order to add another user'
            );
        }

        if (strlen($pass) < 12) {
            throw new IscsiTargetException('Password must be at least 12 characters in length.');
        }

        if ($closeSessions) {
            $this->closeSessionsOnTarget($target);
        }

        // This only supports TPG-level auth, with generate_node_acls=1 (set during creation).
        // ACL-level auth is required for multiple users
        $this->setTpgAuthentication($target, true);
        switch ($userType) {
            case UserType::INCOMING():
                $this->setAuthParameter($target, self::AUTH_PARAM_USER_ID, $user);
                $this->setAuthParameter($target, self::AUTH_PARAM_PASSWORD, $pass);
                break;
            case UserType::OUTGOING():
                $this->setAuthParameter($target, self::AUTH_PARAM_USER_ID_MUTUAL, $user);
                $this->setAuthParameter($target, self::AUTH_PARAM_PASSWORD_MUTUAL, $pass);
                break;
            default:
                throw new IscsiTargetException('Unexpected UserType while adding target CHAP user');
        }

        if ($closeSessions) {
            $this->allowSessionsOnTarget($target);
        }
    }

    /**
     * Remove a CHAP user from an iSCSI target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @param UserType $userType the type of user that will be removed
     * @param string $user the username that will be removed
     */
    public function removeTargetChapUser(string $target, UserType $userType, string $user)
    {
        $targetUsers = $this->listTargetChapUsers($target);
        if (!isset($targetUsers[$userType->value()]) || $targetUsers[$userType->value()] !== $user) {
            throw new IscsiTargetException('The CHAP user does not exist for this target.');
        }

        switch ($userType) {
            case UserType::INCOMING():
                $this->setAuthParameter($target, self::AUTH_PARAM_USER_ID, "");
                $this->setAuthParameter($target, self::AUTH_PARAM_PASSWORD, "");
                break;
            case UserType::OUTGOING():
                $this->setAuthParameter($target, self::AUTH_PARAM_USER_ID_MUTUAL, "");
                $this->setAuthParameter($target, self::AUTH_PARAM_PASSWORD_MUTUAL, "");
                break;
            default:
                throw new IscsiTargetException('Unexpected UserType while removing target CHAP user');
        }

        if (empty($this->listTargetChapUsers($target))) {
            $this->setTpgAuthentication($target, false);
        }
    }

    /**
     * Enable or disable authentication on a target's TPG (Target Portal Group)
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @param bool $enabled set to TRUE to enable authentication
     */
    private function setTpgAuthentication(string $target, bool $enabled)
    {
        try {
            $targetTpgPath = '/iscsi/' . $target . '/tpg1/';
            $attributes = ['authentication=' . (int)$enabled];
            $this->targetcli->setTargetPortalGroupPathAttributes($targetTpgPath, $attributes);
        } catch (RuntimeException $ex) {
            throw new IscsiTargetException(
                'Failed to ' . ($enabled ? 'enable' : 'disable') . ' authentication for ' . $target
            );
        }
    }

    /**
     * List CHAP usernames on an iSCSI target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @param array|null $parameters Optional CHAP authentication configuration, if known
     * @return string[] key is the UserType, value is the username for that UserType
     *   array(
     *       UserType::INCOMING => 'username',
     *       UserType::OUTGOING => 'username',
     *   )
     * Note, keys are only present if there is a user associated with that UserType.
     */
    public function listTargetChapUsers(string $target, array $parameters = null): array
    {
        $userList = [];

        if (is_null($parameters)) {
            $this->checkTargetExistence($target);
            $parameters = $this->getAuthParameters($target);
        }

        $userid = $parameters[self::AUTH_PARAM_USER_ID];
        if (!empty($userid) && $userid !== 'NULL') {
            $userList[UserType::INCOMING] = $userid;
        }

        $useridMutual = $parameters[self::AUTH_PARAM_USER_ID_MUTUAL];
        if (!empty($useridMutual) && $useridMutual !== 'NULL') {
            $userList[UserType::OUTGOING] = $useridMutual;
        }

        return $userList;
    }

    /**
     * Get the CHAP credentials on an iSCSI target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @return array[] key is the UserType, value is an array containing the username and password for that UserType
     *   array(
     *       UserType::INCOMING => array('username', 'password'),
     *       UserType::OUTGOING => array('username', 'password'),
     *   )
     * Note, keys are only present if there is a user associated with that UserType.
     */
    public function getTargetChapUsers(string $target): array
    {
        $this->checkTargetExistence($target);
        $parameters = $this->getAuthParameters($target);

        $userList = $this->listTargetChapUsers($target, $parameters);

        $credentials = [];
        if (isset($userList[UserType::INCOMING])) {
            $password = $parameters[self::AUTH_PARAM_PASSWORD];
            $credentials[UserType::INCOMING] = [$userList[UserType::INCOMING], $password];
        }

        if (isset($userList[UserType::OUTGOING])) {
            $passwordMutual = $parameters[self::AUTH_PARAM_PASSWORD_MUTUAL];
            $credentials[UserType::OUTGOING] = [$userList[UserType::OUTGOING], $passwordMutual];
        }

        return $credentials;
    }

    /**
     * Get the CHAP authentication password for the given iSCSI target.
     *
     * @param string $target
     * @return string
     */
    public function getTargetChapPassword(string $target): string
    {
        $parameters = $this->getAuthParameters($target);
        return $parameters[self::AUTH_PARAM_PASSWORD];
    }

    /**
     * Get the Mutual CHAP authentication password for the given iSCSI target.
     *
     * @param string $target
     * @return string
     */
    public function getTargetMutualChapPassword(string $target): string
    {
        $parameters = $this->getAuthParameters($target);
        return $parameters[self::AUTH_PARAM_PASSWORD_MUTUAL];
    }

    /**
     * Get the authentication parameters for an iSCSI target.
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @return string[] The value of the parameters
     */
    private function getAuthParameters(string $target): array
    {
        return $this->targetcli->getTargetPortalGroupAuthParameters("/iscsi/$target/tpg1/");
    }

    /**
     * Set a specific authentication parameter on a target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @param string $parameter the name of the parameter to retrieve
     * @param string $value the parameter will be set to this value
     */
    private function setAuthParameter(string $target, string $parameter, string $value)
    {
        try {
            $targetTpgPath = '/iscsi/' . $target . '/tpg1/';
            $parameters = [$parameter . '=' . $value];
            $this->targetcli->setTargetPortalGroupAuthParameters($targetTpgPath, $parameters);
        } catch (RuntimeException $ex) {
            throw new IscsiTargetException(
                'Failed to set auth parameter "' . $parameter . '" for "' . $target . '"'
            );
        }
    }

    /**
     * Get the number of active sessions on the target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @return int the number of active sessions on the target
     */
    public function getSessionCount(string $target): int
    {
        $targetSysfsPath = self::CONFIGFS_ISCSI_PATH . $target;
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            if (!$this->filesystem->exists($targetSysfsPath)) {
                return 0;
            }

            return (int) trim($this->filesystem->fileGetContents($targetSysfsPath . '/fabric_statistics/iscsi_instance/sessions'));
        } finally {
            $this->configFSLock->unlock();
        }
    }

    /**
     * List LUNs on a target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @return string[] key is LUN number, value is path to backing file/device
     */
    public function listTargetLuns(string $target): array
    {
        $this->checkTargetExistence($target);

        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $lunUdevPaths = $this->filesystem->glob(
                self::CONFIGFS_ISCSI_PATH . $target . '/tpgt_1/lun/lun_*/*/udev_path'
            );
            $lunRegex = '%^' . self::CONFIGFS_ISCSI_PATH . $target . '/tpgt_1/lun/lun_(?P<lunId>[^/]+)%';

            $lunList = [];

            foreach ($lunUdevPaths as $udevPath) {
                if (preg_match($lunRegex, $udevPath, $matches)) {
                    $lunId = $matches['lunId'];
                    try {
                        $lunList[$lunId] = trim($this->getFileContents($udevPath));
                    } catch (Exception $e) {
                        $this->logger->warning('TAR0004 Error getting path for target LUN', ['target' => $target, 'lunId' => $lunId, 'exception' => $e]);
                    }
                }
            }
        } finally {
            $this->configFSLock->unlock();
        }

        return $lunList;
    }

    /**
     * Gets a hash map with keys udev path and values block name.
     *
     * @return string[] key is udev path, value is block name
     */
    public function getBlockBackingStoreMap(): array
    {
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $blockUdevPathFiles = $this->filesystem->glob(self::CONFIGFS_BACKSTORE_PATH . 'iblock_*/*/udev_path');
            $blockPathRegex = '%^' . self::CONFIGFS_BACKSTORE_PATH .
                'iblock_(?P<blockNumber>[^/]+)/(?P<blockName>[^/]+)%';

            $blockMap = [];

            foreach ($blockUdevPathFiles as $udevPathFile) {
                if (preg_match($blockPathRegex, $udevPathFile, $matches)) {
                    $blockNumber = $matches['blockNumber'];
                    $blockName = $matches['blockName'];
                    try {
                        $udevPath = trim($this->getFileContents($udevPathFile));
                        $blockMap[$udevPath] = $blockName;
                    } catch (Exception $e) {
                        $this->logger->warning(
                            'TAR0005 Error getting udev path',
                            ['blockNumber' => $blockNumber, 'exception' => $e]
                        );
                    }
                }
            }
        } finally {
            $this->configFSLock->unlock();
        }

        return $blockMap;
    }

    /**
     * List the volume GUID for each LUN in an iSCSI target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @return string[] key is LUN number, value is volume GUID
     */
    public function listTargetVolumeGuids(string $target): array
    {
        $lunToDattoFileMap = $this->listTargetLuns($target);
        $guids = [];
        foreach ($lunToDattoFileMap as $lun => $path) {
            if ($this->isLoopDevicePath($path)) {
                $guids[$lun] = $this->getGuidFromLoop($path);
            } else {
                $guids[$lun] = $this->getGuidFromMapper($path);
            }
        }

        return $guids;
    }

    /**
     * Create a new target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     */
    public function createTarget(string $target)
    {
        if ($this->doesTargetExist($target)) {
            throw new IscsiTargetExistsException("Target \"$target\" already exists. Please delete it first.");
        }

        $this->targetcli->createTarget($target);

        try {
            $tpgPath = '/iscsi/' . $target . '/tpg1';

            $parameters = [
                'DataDigest=None',
                'FirstBurstLength=262144',
                'HeaderDigest=None',
                'InitialR2T=No',
                'MaxBurstLength=524288',
                'MaxRecvDataSegmentLength=262144',
            ];

            $this->targetcli->setTargetPortalGroupParameters($tpgPath, $parameters);
        } catch (RuntimeException $e) {
            $this->logger->error('TAR0002 Deleting target due to error', ['target' => $target, 'exception' => $e]);
            $this->deleteTarget($target);
            throw new IscsiTargetException('Failed to set target parameters', 0, $e);
        }

        try {
            $attributes = [
                'default_cmdsn_depth=32', // 32 is used because that's what it was set to with IETD; however, the LIO Administrator's Manual suggests 16 for 1GbE, 64 for 10GbE: should we set it dynamically, based on link speed?
                'demo_mode_write_protect=0',
                'generate_node_acls=1', // enable "demo mode", allowing all initiators to connect without creating ACLs
            ];
            $this->targetcli->setTargetPortalGroupPathAttributes($tpgPath, $attributes);
        } catch (RuntimeException $e) {
            $this->logger->error('TAR0016 Deleting target due to error', ['target' => $target, 'exception' => $e]);
            $this->deleteTarget($target);
            throw new IscsiTargetException('Failed to set target attributes', 0, $e);
        }
    }

    /**
     * Kill all active connections on a target
     *
     * This is accomplished by disabling the target's TPG.
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @return bool TRUE if the TPG was successfully disabled
     */
    public function closeSessionsOnTarget(string $target): bool
    {
        try {
            $targetTpgPath = '/iscsi/' . $target . '/tpg1/';
            $this->targetcli->setTpgState($targetTpgPath, false);
        } catch (RuntimeException $ex) {
            $this->logger->error('TAR0007 Failed to disable TPG due to error', ['target' => $target, 'exception' => $ex]);
            return false;
        }
        return true;
    }

    /**
     * Allow connections to a target
     *
     * This is accomplished by enabling the target's TPG.
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @return bool TRUE if the TPG was successfully enabled
     */
    public function allowSessionsOnTarget(string $target): bool
    {
        try {
            $targetTpgPath = '/iscsi/' . $target . '/tpg1/';
            $this->targetcli->setTpgState($targetTpgPath, true);
        } catch (RuntimeException $ex) {
            $this->logger->error('TAR0008 Failed to enable TPG due to error', ['target' => $target, 'exception' => $ex]);
            return false;
        }
        return true;
    }

    /**
     * Remove all iSCSI entities related to an Agent's volumes
     *
     * @param Agent $agent
     */
    public function removeAgentIscsiEntities(Agent $agent)
    {
        $volumes = $agent->getVolumes();
        foreach ($volumes as $volume) {
            if (!$volume->isIncluded()) {
                continue;
            }

            $basePath = $agent->getDataset()->getMountPoint() . '/' . $volume->getGuid();
            $rawPath = $basePath . '.datto';
            $encryptedPath = $basePath . '.detto';
            if ($this->filesystem->exists($encryptedPath)) {
                $volumePath = $encryptedPath;
            } else {
                $volumePath = $rawPath;
            }
            $checksumPath = $basePath . '.checksum';

            if ($this->filesystem->exists($volumePath)) {
                $targets = $this->getTargetsByPath($volumePath);
                foreach ($targets as $target) {
                    $this->deleteTarget($target);
                }

                $backstores = $this->getBackstoresByPath($volumePath);
                foreach ($backstores as $backstore) {
                    $this->deleteBackstore($backstore, true);
                }
            }

            if ($this->filesystem->exists($checksumPath)) {
                $checksumBackstores = $this->getBackstoresByPath($checksumPath);
                foreach ($checksumBackstores as $checksumBackstore) {
                    $this->deleteBackstore($checksumBackstore, true);
                }
            }
        }
    }

    /**
     * Get a list of backstore udev_paths associated with a given path
     *
     * @param string $path the path to a file or block device
     * @return array the udev_paths of backstores associated with the provided path
     */
    private function getBackstoresByPath(string $path): array
    {
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $backstoreUdevPaths = $this->filesystem->glob(self::CONFIGFS_BACKSTORE_PATH . '*/*/udev_path');

            // Include dm-crypt devices.
            // Use both the /dev/dm-* and /dev/mapper/*-crypt-* paths.
            $devCryptDevices = $this->dmCryptManager->getDMCryptDevicesForFile($path);
            $devMapperCryptDevices = array_map(function (string $dmDeviceName) {
                return '/dev/mapper/' . $dmDeviceName;
            }, $this->dmCryptManager->getDMCryptDevicesForFile($path, true));

            // Include loop devices.
            $loopDevices = array_map(function (LoopInfo $loopDevice) {
                return $loopDevice->getPath();
            }, $this->loopManager->getLoopsOnFile($path));

            $searchPaths = array_merge([$path], $devCryptDevices, $devMapperCryptDevices, $loopDevices);

            $backstores = [];
            foreach ($backstoreUdevPaths as $udevPath) {
                $backstorePath = trim($this->filesystem->fileGetContents($udevPath));
                if (!in_array($backstorePath, $searchPaths, true)) {
                    continue;
                }

                if (!in_array($backstorePath, $backstores, true)) {
                    $backstores[] = $backstorePath;
                }
            }
        } finally {
            $this->configFSLock->unlock();
        }

        return $backstores;
    }

    /**
     * Delete a target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     */
    public function deleteTarget(string $target)
    {
        $this->checkTargetExistence($target);

        $this->closeSessionsOnTarget($target);

        $luns = $this->listTargetLuns($target);
        $temporary = $this->isTargetTemporary($target);
        foreach ($luns as $lunPath) {
            $this->deleteBackstore($lunPath, $temporary, $target);
        }

        $this->targetcli->deleteTarget($target);
    }

    /**
     * Add a LUN to a target
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @param string $path the path to the file or block device to use for the LUN
     * @param bool $readOnly set to TRUE to make the LUN (or entire TPG for fileio backstores) read-only
     * @param bool $writeBack set to TRUE to enable write-back caching; uses write-through by default
     * @param string|null $wwn WWN for the LUN (optional); VMware displays this in the volume's durableName property
     * @param string[] $backstoreAttributes attributes to apply to the LUN's backstore
     * @return int LUN ID
     */
    public function addLun(
        string $target,
        string $path,
        bool $readOnly = false,
        bool $writeBack = false,
        string $wwn = null,
        array $backstoreAttributes = []
    ): int {
        $this->checkTargetExistence($target);

        $backstore = $this->createBackstore(
            $target,
            $path,
            $readOnly,
            $writeBack,
            $wwn,
            $backstoreAttributes,
            $this->isTargetTemporary($target)
        );

        $lunsBeforeCreate = array_keys($this->listTargetLuns($target));

        try {
            $this->targetcli->createLun($target, $backstore);
        } catch (RuntimeException $e) {
            $this->logger->error('TAR0001 Deleting backstore due to error', ['path' => $path, 'exception' => $e]);
            $this->deleteBackstore($path, $this->isTargetTemporary($target), $target);
            throw new IscsiTargetException('Failed to activate LUN', 0, $e);
        }

        $lunsAfterCreate = array_keys($this->listTargetLuns($target));

        $newLuns = array_diff($lunsAfterCreate, $lunsBeforeCreate);
        if (count($newLuns) !== 1) {
            throw new IscsiTargetException('Unexpected new LUN count');
        }

        return end($newLuns);
    }

    private function isTargetTemporary(string $target): bool
    {
        return strpos($target, "datto.dev.temp.") !== false;
    }

    private function createBackstore(
        string $target,
        string $path,
        bool $readOnly = false,
        bool $writeBack = false,
        string $wwn = null,
        array $attributes = [],
        bool $temporary = false
    ): string {
        if (!$this->filesystem->isFile($path) && !$this->filesystem->isBlockDevice($path)) {
            throw new IscsiTargetException("Refusing to create a backstore with a path ($path) that is " .
                "neither a regular file nor block device nor symlink to one of the above.");
        }

        $backstoreName = $this->generateBackstoreName($path, $temporary);

        if ($this->filesystem->isFile($path)) {
            // LIO's fileio backend is limited to 8MB I/O chunks.
            // See: https://git.kernel.org/pub/scm/linux/kernel/git/stable/linux-stable.git/commit/?id=82bc9d04f4281276b8941b09a9306e15d4dc53f6
            // Since the agent accesses targets in 10MB and 12MB chunks, we wrap all files in a loop device.
            $loopDevice = $this->loopManager->create($path);
            $path = $loopDevice->getPath();
        }

        try {
            $backstorePath = $this->targetcli->createBackstore(
                $target,
                $backstoreName,
                $path,
                $readOnly,
                $writeBack,
                $wwn,
                $attributes
            );
        } catch (RuntimeException $e) {
            // Creation may have failed due to leftover backstore. If it's not being used, delete it and try again
            if ($this->isBackstoreInUse($path)) {
                throw new IscsiTargetException('Failed to create backstore "' . $path . '"', 0, $e);
            } else {
                $this->logger->notice('TAR0003 Deleting orphaned backstore and trying again due to error', ['path' => $path, 'exception' => $e]);
                $this->deleteBackstore($path, $temporary, $target);

                // Todo: catch RuntimeException???
                $backstorePath = $this->targetcli->createBackstore(
                    $target,
                    $backstoreName,
                    $path,
                    $readOnly,
                    $writeBack,
                    $wwn,
                    $attributes
                );
            }
        }

        return $backstorePath;
    }

    /**
     * Generate a name for a backstore based on its backing file path
     *
     * 32-bit FNV-1a is used generate an 8-character name based on the path.
     * '_temp' is appended for temporary backstores.
     * This prevents kernel messages about truncating to 16 bytes when responding to INQUIRY_MODEL requests:
     *
     *    Jan 01 01:23:45 device kernel: dev[00000000deadbeef]: Backstore name 'thisNameIsTooLong' is too long for
     *        INQUIRY_MODEL, truncating to 16 bytes
     *
     * @param string $path
     * @param bool $temporary
     * @return string
     */
    private function generateBackstoreName(string $path, bool $temporary = false): string
    {
        $backstoreParentDirectory = basename(dirname($path));
        $backstoreShortName = basename($path, '.datto'); // for agents, this will be the volume GUID
        return hash('fnv1a32', $backstoreParentDirectory . '_' . $backstoreShortName) . ($temporary ? '_temp' : '');
    }

    /**
     * Deletes the backing store and associated loop for the specified path.
     * This uses multiple methods to find the backing store name to increase
     * the chances of success.  The goal is to be fault resilient.
     * It will not generate an exception if the backing store is not found,
     * but it will generate an exception if an error occurs while trying to
     * delete the backing store.
     *
     * @param string $path Path to the backing store
     * @param bool $temporary
     * @param string $target
     * @return bool true if deleted, false if no backing store was found
     */
    private function deleteBackstore(string $path, bool $temporary, string $target = '[UNKNOWN]')
    {
        $isLoopDevice = $this->isLoopDevicePath($path);
        if ($isLoopDevice) {
            // Get the backing file and backstore name for the current loop device
            try {
                $loopBackingFile = trim($this->getFileContents('/sys/block/' . basename($path) . '/loop/backing_file'));
                $backstoreName = $this->generateBackstoreName($loopBackingFile, $temporary);
            } catch (Exception $e) {
                $loopBackingFile = '';   // the loop device does not exist
                $backstoreName = '';     // unable to get backstore name
                $this->logger->warning('TAR0009 Loop does not exist', ['path' => $path, 'exception' => $e]);
            }
            // Verify that the loop backing file refers to the correct backstore name
            $backstoreBlockMap = $this->getBlockBackingStoreMap();
            if (array_key_exists($path, $backstoreBlockMap) && $backstoreName !== $backstoreBlockMap[$path]) {
                $loopBackingFile = '';   // the existing loop device is not connected to this backstore
                $backstoreName = $backstoreBlockMap[$path];
                $this->logger->warning('TAR0006 Loop will not be deleted because it is not associated with backstore', ['path' => $path, 'backstoreName' => $backstoreName]);
            }
            // Make sure we have a backstore name from one of the two methods above
            if ($backstoreName === '') {
                $this->logger->warning("TAR0010 Can't find backstore for loop", ['path' => $path]);
                return false;
            }
        } else {
            $backstoreName = $this->generateBackstoreName($path, $temporary);
        }

        $this->targetcli->deleteBackstore($backstoreName, $path, $target);

        if ($isLoopDevice && $loopBackingFile !== '') {
            $loopDevices = array_filter(
                $this->loopManager->getLoopsOnFile($loopBackingFile),
                function (LoopInfo $loopDevice) use ($path) {
                    // In case multiple loop devices are pointed to the same $loopBackingFile, find the one at $path.
                    return $loopDevice->getPath() === $path;
                }
            );
            foreach ($loopDevices as $loopDevice) {
                $this->loopManager->destroy($loopDevice);
            }
        }

        return true;
    }

    /**
     * Save the config
     *
     * @return true if saved, false if not saved because the LIO configuration
     *     has not been restored yet.
     */
    public function writeChanges(): bool
    {
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);

        try {
            if (!$this->isIscsiConfigurationRestored()) {
                $this->logger->warning("TAR0013 LIO configuration has not been restored yet -- skipping save.");
                return false;
            }

            $tempFile = $this->filesystem->tempName('/dev/shm', 'targets-');
            $this->targetctl->saveConfiguration($tempFile);

            $config = json_decode($this->filesystem->fileGetContents($tempFile), true);

            // Remove temporary backstores
            $deletedBackstores = [];
            foreach ($config['storage_objects'] as $key => $backstore) {
                if ($this->isBackstoreNotPersisted($backstore)) {
                    $deletedBackstores[] = '/backstores/block/' . $backstore['name'];
                    unset($config['storage_objects'][$key]);
                }
            }
            $config['storage_objects'] = array_values($config['storage_objects']);

            // Remove temporary targets and LUNs which reference deleted backstores
            $targets = &$config['targets'];
            foreach ($targets as $targetKey => &$target) {
                if ($this->isTargetTemporary($target['wwn'])) {
                    unset($targets[$targetKey]);
                } elseif (isset($target['tpgs'][0]['luns'])) {
                    $luns = &$target['tpgs'][0]['luns'];
                    if (is_array($luns) && count($luns) > 0) {
                        foreach ($luns as $lunKey => &$lun) {
                            if (isset($lun['storage_object']) && in_array($lun['storage_object'], $deletedBackstores)) {
                                unset($luns[$lunKey]);
                            }
                        }
                        $luns = array_values($luns);
                        // If we've deleted all the LUNs for a target, then remove the target too
                        if (count($target['tpgs']) === 1 && count($luns) === 0) {
                            unset($targets[$targetKey]);
                        }
                    }
                }
            }
            $targets = array_values($targets);

            if ($this->filesystem->filePutContents($tempFile, json_encode($config, JSON_PRETTY_PRINT)) === false) {
                throw new IscsiTargetException('Failed to update temporary LIO configuration');
            }

            if (!$this->filesystem->rename($tempFile, self::LIO_CONFIG_FILE)) {
                throw new IscsiTargetException('Failed to save LIO configuration');
            }
        } finally {
            $this->configFSLock->unlock();
        }

        $this->logger->debug("TAR0011 Saved LIO configuration.");

        return true;
    }

    /**
     * Clear the configuration
     */
    public function clearConfiguration()
    {
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);

        try {
            $this->clearIscsiConfigurationRestored();
            $this->targetctl->clearConfiguration();
        } finally {
            $this->configFSLock->unlock();
        }
    }

    /**
     * Sets the flag (key file) that indicates the LIO kernel target
     * configuration is restored.  This flag is cleared at boot time.
     */
    public function setIscsiConfigurationRestored()
    {
        $time = $this->dateTimeService->getTime();
        $this->shmConfig->set(self::LIO_CONFIG_RESTORED_KEY, $time);
        $this->logger->info("TAR0012 LIO configuration has been restored.");
    }

    /**
     * Clears the flag (key file) that indicates the LIO kernel target
     * configuration is restored.
     */
    public function clearIscsiConfigurationRestored()
    {
        $this->shmConfig->clear(self::LIO_CONFIG_RESTORED_KEY);
        $this->logger->info("TAR0014 LIO configuration is no-longer valid.");
    }

    /**
     * Determines if the LIO kernel target configuration has been restored
     * after boot.  This needs to be restored before any new saves are done to
     * the target configuration, or existing saved targets will be lost.
     *
     * @return bool
     */
    public function isIscsiConfigurationRestored(): bool
    {
        return $this->shmConfig->has(self::LIO_CONFIG_RESTORED_KEY);
    }

    private function isBackstoreTemporary(string $backstoreName): bool
    {
        $tempNeedle = '_temp';
        return substr($backstoreName, -strlen($tempNeedle)) === $tempNeedle;
    }

    private function isLoopDevicePath(string $path): bool
    {
        return strpos($path, '/dev/loop') === 0;
    }

    /**
     * Determines if a backstore is used by a restore.
     *
     * @param string $backstoreName
     * @return bool
     */
    private function isRestoreBackstore(string $backstoreName): bool
    {
        $backstoreUdevPaths = $this->filesystem->glob(
            self::CONFIGFS_BACKSTORE_PATH . '*/' . $backstoreName . '/udev_path'
        );

        if (count($backstoreUdevPaths) > 1) {
            throw new IscsiTargetException('Critical iSCSI issue: multiple backstores with the same name!');
        }

        if (count($backstoreUdevPaths) !== 1) {
            $this->logger->warning('TAR0015 Unable to locate backstore', ['backstoreName' => $backstoreName]);
            return false;
        }

        $backstoreUdevPathContents = trim($this->getFileContents($backstoreUdevPaths[0]));

        if ($this->isLoopDevicePath($backstoreUdevPathContents)) {
            $loopInfo = $this->loopManager->getLoopInfo($backstoreUdevPathContents);
            $directoryName = basename(dirname($loopInfo->getBackingFilePath()));
        } else {
            $directoryName = basename(dirname($backstoreUdevPathContents));
        }

        $needle = '-' . RestoreType::ISCSI_RESTORE;
        return substr($directoryName, -strlen($needle)) === $needle;
    }

    /**
     * @param array $backstore
     * @return bool
     */
    private function isBackstoreNotPersisted(array $backstore): bool
    {
        return $this->isBackstoreTemporary($backstore['name']) ||
            $this->isRestoreBackstore($backstore['name']) ||
            $this->isLoopDevicePath($backstore['dev']);
    }

    /**
     * Check whether the backstore is attached to any targets
     *
     * @param string $path
     * @return bool
     */
    private function isBackstoreInUse(string $path)
    {
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $lunUdevPaths = $this->filesystem->glob(self::CONFIGFS_ISCSI_PATH . '*/tpgt_1/lun/lun_*/*/udev_path');

            foreach ($lunUdevPaths as $udevPath) {
                if (trim($this->filesystem->fileGetContents($udevPath)) === $path) {
                    return true;
                }
            }
        } finally {
            $this->configFSLock->unlock();
        }
        return false;
    }

    /**
     * Check if the target exists and (by default) throws an exception
     *
     * @param string $target the target's iSCSI Qualified Name (IQN)
     * @return bool TRUE if the target exists
     */
    private function checkTargetExistence(string $target): bool
    {
        $targetExists = in_array($target, $this->listTargets());
        if (!$targetExists) {
            throw new IscsiTargetNotFoundException("Target \"$target\" does not exist. Please create it first.");
        }

        return true;
    }

    /**
     * Find the Guid for a local loop
     *
     * @param string $loop Loop path e.g. /dev/loop1
     * @return string guid for the associated loop
     */
    private function getGuidFromLoop(string $loop)
    {
        $backingFile = $this->losetup->getBackingFile($loop);
        if (empty($backingFile)) {
            throw new IscsiTargetException("Unable to get target guid for $loop");
        }

        if (strpos($backingFile, 'homePool') !== false) {
            $regexBasePath = '/homePool/';
        } else {
            $regexBasePath = '/home/agents/';
        }
        $regexPattern = '#^.*' . $regexBasePath . '(.*)/(.*)\..*$#';
        return preg_replace($regexPattern, "$2", $backingFile);
    }

    /**
     * Find the GUID for a local dev mapper
     *
     * @param string $mapper Mapper path e.g. /dev/mapper/...
     * @return string GUID for the associated mapper
     */
    private function getGuidFromMapper(string $mapper)
    {
        if (preg_match('#([0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12})[^/]*$#i', $mapper, $match)) {
            return $match[1];
        }
        throw new IscsiTargetException("Unable to get target guid for $mapper");
    }

    /**
     * Get the contents of a file.
     * Generates an exception if the file cannot be read.
     *
     * @param $filename
     * @return string
     */
    private function getFileContents(string $filename): string
    {
        $contents = $this->filesystem->fileGetContents($filename);
        if ($contents === false) {
            throw new IscsiTargetException("Error reading file '$filename'");
        }
        return $contents;
    }
}
