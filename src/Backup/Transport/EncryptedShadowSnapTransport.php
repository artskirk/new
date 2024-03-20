<?php

namespace Datto\Backup\Transport;

use Datto\Alert\AlertManager;
use Datto\AppKernel;
use Datto\Asset\UuidGenerator;
use Datto\Backup\BackupException;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfig;
use Datto\Core\Network\DeviceAddress;
use Datto\Samba\UserService;
use Datto\Utility\File\Lsof;
use Datto\Utility\File\LsofEntry;
use Datto\Iscsi\Cleaner;
use Datto\Iscsi\InitiatorException;
use Datto\Iscsi\IscsiTarget;
use Datto\Iscsi\RemoteInitiator;
use Datto\Iscsi\UserType;
use Datto\Samba\SambaManager;
use Datto\Common\Utility\Filesystem;
use Datto\Util\RetryHandler;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Handles Samba and Iscsi data transfer from the ShadowSnap agent to the device.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class EncryptedShadowSnapTransport extends BackupTransport
{
    const ATTACH_AGENT_RETRIES = 5;
    const ATTACH_AGENT_WAIT_TIME_SECONDS = 1;
    const RELEASE_IMAGE_FILE_RETRIES = 30;
    const RELEASE_IMAGE_FILE_WAIT_TIME_SECONDS = 1;
    const SAMBA_WRITE_CACHE_SIZE = '1048576';

    private string $assetKeyName;
    private DeviceLoggerInterface $logger;
    private IscsiTarget $iscsiTarget;
    private array $volumes;
    private SambaManager $sambaManager;
    private AgentConfig $agentConfig;
    private DeviceAddress $deviceAddress;
    private RemoteInitiator $iscsiInitiator;
    private Cleaner $iscsiCleaner;
    private Filesystem $filesystem;
    private RetryHandler $retryHandler;
    private Lsof $lsof;
    private UuidGenerator $uuidGenerator;
    private AlertManager $alertManager;
    private string $shareName;
    private string $agentPath;
    private string $targetHost;

    public function __construct(
        string $assetKeyName,
        DeviceLoggerInterface $logger,
        IscsiTarget $iscsiTarget = null,
        SambaManager $sambaManager = null,
        AgentConfig $agentConfig = null,
        DeviceAddress $deviceAddress = null,
        RemoteInitiator $iscsiInitiator = null,
        Cleaner $iscsiCleaner = null,
        Filesystem $filesystem = null,
        RetryHandler $retryHandler = null,
        Lsof $lsof = null,
        UuidGenerator $uuidGenerator = null,
        AlertManager $alertManager = null
    ) {
        $this->assetKeyName = $assetKeyName;
        $this->logger = $logger;
        $this->iscsiTarget = $iscsiTarget ?: new IscsiTarget();
        $this->agentConfig = $agentConfig ?: new AgentConfig($this->assetKeyName);
        $this->deviceAddress = $deviceAddress ?:
            AppKernel::getBootedInstance()->getContainer()->get(DeviceAddress::class);
        $this->iscsiInitiator = $iscsiInitiator ?: new RemoteInitiator($this->assetKeyName, $this->logger);
        $this->iscsiCleaner = $iscsiCleaner ?: new Cleaner(
            $this->assetKeyName,
            $this->iscsiTarget,
            $this->iscsiInitiator,
            null,
            $this->logger
        );
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->sambaManager = $sambaManager ?? AppKernel::getBootedInstance()->getContainer()->get(SambaManager::class);
        $this->sambaManager->setLogger($this->logger);
        $this->retryHandler = $retryHandler ?: new RetryHandler($this->logger);
        $this->lsof = $lsof ?: new Lsof();
        $this->uuidGenerator = $uuidGenerator ?: new UuidGenerator();
        $this->alertManager = $alertManager ?: new AlertManager();

        $this->shareName = $this->uuidGenerator->get();
        $this->agentPath = '/home/agents/' . $this->assetKeyName;
        $this->volumes = [];
        $this->targetHost = '';
    }

    /**
     * @inheritdoc
     */
    public function setup(array $imageLoopsOrFiles, array $checksumFiles, array $allVolumes)
    {
        $this->initialize();

        // Set up all iSCSI targets
        foreach ($imageLoopsOrFiles as $guid => $loopDev) {
            $spaceTotal = $allVolumes[$guid]['spaceTotal'];
            $this->setupVolume($guid, $loopDev, $spaceTotal);
        }

        // Register so the iSCSI targets are known
        $this->setupRemoteInitiator();

        // Attach the volumes to the known iSCSI targets
        foreach ($imageLoopsOrFiles as $guid => $loopDev) {
            $this->attachVolume($guid);
        }

        $this->finalize();
    }

    /**
     * @inheritdoc
     */
    public function getQualifiedName(): string
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function getPort(): int
    {
        return 0;
    }

    /**
     * @inheritdoc
     */
    public function getVolumeParameters(): array
    {
        return $this->volumes;
    }

    /**
     * @inheritdoc
     */
    public function getApiParameters(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        $this->waitUntilImageFilesReleased();
        $this->cleanupIscsi();
        $this->sambaManager->removeShareByPath($this->agentPath);
    }

    /**
     * Initialize the iSCSI target
     */
    private function initialize()
    {
        $this->volumes = [];
        $this->setTargetHost();
    }

    /**
     * Setup volume information and create iSCSI targets for volume loops / files.
     *
     * @param string $volumeGuid
     * @param string $loopDev
     * @param string $spaceTotal
     */
    private function setupVolume(string $volumeGuid, string $loopDev, string $spaceTotal)
    {
        $this->createEmptyImageFile($volumeGuid, $spaceTotal);

        $exportPath = $this->getExportPath();
        $imageSambaFile = $exportPath . '\\' . $volumeGuid . '.datto';

        // Set sector offset to zero as the dm device is offset instead
        $offset = 0;

        $connection = $this->setupIscsiAndGetConnectionInformation($volumeGuid, $loopDev);

        $this->volumes[$volumeGuid] = [
            "image" => $imageSambaFile,
            "offset" => $offset,
            "connection" => $connection
        ];
    }

    /**
     * Attach the volume to the target and set the volume's path
     *
     * @param string $volumeGuid
     */
    private function attachVolume(string $volumeGuid)
    {
        $connection = $this->volumes[$volumeGuid]['connection'];
        $name = $connection['targetName'];
        $user = $connection['targetUser'];
        $password = $connection['targetPassword'];
        $volumePath = $this->attachAgent($name, $user, $password);
        $this->volumes[$volumeGuid]['blockDevice'] = $volumePath;
    }

    /**
     * Update the iSCSI target
     */
    private function finalize()
    {
        $this->createSambaShare();
    }

    /**
     * Determine and set the target host
     */
    private function setTargetHost()
    {
        if ($this->agentConfig->has('hostOverride')) {
            $this->targetHost = $this->agentConfig->get('hostOverride');
        } else {
            $this->targetHost = $this->deviceAddress->getLocalIp($this->agentConfig->getFullyQualifiedDomainName());
        }
    }

    /**
     * Create empty datto image file otherwise ShadowSnap will complain
     *
     * @param string $volumeGuid
     * @param string $spaceTotal
     */
    private function createEmptyImageFile(string $volumeGuid, string $spaceTotal)
    {
        $imagePath = $this->agentPath . '/' . $volumeGuid . '.datto';

        if ($this->filesystem->exists($imagePath)) {
            $this->filesystem->unlink($imagePath);
        }

        try {
            $fp = $this->filesystem->open($imagePath, "w");
            $this->filesystem->truncate($fp, $spaceTotal);
        } finally {
            $this->filesystem->close($fp);
        }

        $this->filesystem->chmod($imagePath, 0666);
        $this->filesystem->chown($imagePath, 'nobody');
        $this->filesystem->chgrp($imagePath, 'nogroup');
    }

    /**
     * Get the Samba share export path
     *
     * @return string
     */
    private function getExportPath(): string
    {
        return sprintf('\\\\%s\\%s', $this->targetHost, $this->shareName);
    }

    /**
     * Setup iSCSI target, add user, add lun, setup remote initiator, attach agent, and return connection information.
     *
     * @param string $volumeGuid
     * @param string $loopDev
     * @return string[]
     */
    private function setupIscsiAndGetConnectionInformation(string $volumeGuid, string $loopDev)
    {
        $connection = $this->getIscsiConnectionData();
        $name = $connection['targetName'];
        $user = $connection['targetUser'];
        $password = $connection['targetPassword'];
        $this->createIscsiTarget($name);
        $this->addIscsiUser($name, $user, $password);
        $this->logger->debug("IBT0002 Creating iscsi lun for backup image $loopDev for volume $volumeGuid");
        $this->addLun($name, $loopDev);
        return $connection;
    }

    /**
     * Create the Samba share
     */
    private function createSambaShare()
    {
        $sambaShare = $this->sambaManager->createShare($this->shareName, $this->agentPath);

        $shareProperties = [
            'public' => 'yes',
            'guest ok' => 'yes',
            'valid users' => '',
            'admin users' => '',
            'writable' => 'yes',
            'force user' => 'root',
            'force group' => 'root',
            'create mask' => '777',
            'directory mask' => '777',
            'write cache size' => self::SAMBA_WRITE_CACHE_SIZE,
            'browsable' => 'no'
        ];

        $sambaShare->setProperties($shareProperties);
        $this->sambaManager->sync();
    }

    /**
     * Get the iSCSI connection target name, user, and password.
     *
     * @return array
     */
    private function getIscsiConnectionData(): array
    {
        $rand = substr(sha1(microtime() . random_int(PHP_INT_MIN, PHP_INT_MAX)), 0, 8);
        $iscsiTargetName = $this->iscsiTarget->makeTargetNameTemp($this->assetKeyName . '-' . $rand);

        $iscsiUser = "user" . substr(sha1(microtime() . random_int(PHP_INT_MIN, PHP_INT_MAX)), 0, 4);
        // IscsiInitiator from Microsoft supports: 12 =< len($password) =< 16
        $iscsiPassword = substr(sha1(microtime() . random_int(PHP_INT_MIN, PHP_INT_MAX)), 0, 14);

        return [
            'targetName' => $iscsiTargetName,
            'targetUser' => $iscsiUser,
            'targetPassword' => $iscsiPassword
        ];
    }

    /**
     * Create the iSCSI target
     *
     * @param string $targetName
     */
    private function createIscsiTarget(string $targetName)
    {
        $this->logger->debug('IBT0001 Creating iSCSI target ' . $targetName);
        $this->iscsiTarget->createTarget($targetName);
    }

    /**
     * Add user to the given iSCSI target
     *
     * @param string $targetName
     * @param string $user
     * @param string $password
     */
    private function addIscsiUser(string $targetName, string $user, string $password)
    {
        $this->iscsiTarget->addTargetChapUser($targetName, UserType::INCOMING(), $user, $password);
        $this->logger->debug("ISC0103 CHAP User added successfully");
    }

    /**
     * Add lun to the given iSCSI target
     *
     * @param string $targetName
     * @param string $dmDevice
     */
    private function addLun(string $targetName, string $dmDevice)
    {
        $this->iscsiTarget->addLun($targetName, $dmDevice);
        $this->logger->debug("ISC0104 LUN Added successfully");
    }

    /**
     * Setup the iSCSI remote initiator
     */
    private function setupRemoteInitiator()
    {
        try {
            if ($this->iscsiInitiator->isPortalRegistered()) {
                return;
            }
            $this->logger->debug("SST0000 Agent not registered with iSCSI portal, registering...");
            $this->iscsiInitiator->registerPortal($this->targetHost);
            $this->logger->debug("SST0001 Agent registered with portal correctly.");
        } catch (InitiatorException $x) {
            $errorMessage = "Failed to check iSCSI portal registration status. The agent may not be properly paired with the device.";
            $this->logger->error("ENC1014 $errorMessage");
            throw new BackupException("BAK0526" . $errorMessage);
        }
        $this->alertManager->clearAlert($this->assetKeyName, 'ENC1014');
    }

    /**
     * Attach the agent to the iSCSI target
     *
     * @param string $targetName
     * @param string $user
     * @param string $password
     * @return string Volume path
     */
    private function attachAgent(string $targetName, string $user, string $password): string
    {
        $this->logger->debug("ISC0102 Attaching agent to iSCSI target \"$targetName\"...");

        $volumePath = $this->retryHandler->executeAllowRetry(
            function () use ($targetName, $user, $password) {
                return $this->attachAgentAttempt($targetName, $user, $password);
            },
            static::ATTACH_AGENT_RETRIES,
            static::ATTACH_AGENT_WAIT_TIME_SECONDS
        );
        $this->logger->debug("ISC0104 Agent attached.");

        if (empty($volumePath)) {
            throw new BackupException("BAK0125 No volumes came up when we attached the iSCSI disk");
        }
        return $volumePath;
    }

    /**
     * Attempt to attach the agent to the iSCSI target
     *
     * @param string $targetName
     * @param string $user
     * @param string $password
     * @return bool|string
     */
    private function attachAgentAttempt(string $targetName, string $user, string $password)
    {
        $this->iscsiInitiator->loginToTarget($targetName, $user, $password);
        $volumePath = $this->iscsiInitiator->discoverVolume($targetName);
        return $volumePath;
    }

    /**
     * Wait until the image files are released by the agent
     */
    private function waitUntilImageFilesReleased()
    {
        $this->logger->debug("BAK2586 Waiting for .datto/.detto files to be released (max. 30 sec) ...");

        $this->retryHandler->executeAllowRetry(
            function () {
                $this->haveImageFilesBeenReleased();
            },
            static::RELEASE_IMAGE_FILE_RETRIES,
            static::RELEASE_IMAGE_FILE_WAIT_TIME_SECONDS
        );
    }

    /**
     * Determine if the image files have been released
     *
     * @return bool
     */
    private function haveImageFilesBeenReleased(): bool
    {
        $openImageFiles = $this->lsof->getFilesInDir($this->agentPath, function (LsofEntry $entry) {
            return preg_match('/\.d[ae]tto$/', $entry->getName());
        });
        $filesReleased = count($openImageFiles) === 0;
        return $filesReleased;
    }

    /**
     * Cleanup iSCSI artifacts
     */
    private function cleanupIscsi()
    {
        try {
            $this->logger->debug('BAK0351 Telling iSCSI initiator to logout...');
            $this->iscsiCleaner->pruneiSCSIInitiator();
        } catch (Throwable $x) {
            $this->logger->error('BAK0352 Failed to prune iSCSI initiator on agent side', ['exception' => $x]);
        }

        try {
            // finish pruning on the device side.
            $this->logger->debug('ISC0031 Pruning iSCSI targets...');
            $this->iscsiCleaner->pruneAgentIscsiTargets();
        } catch (Throwable $exception) {
            $this->logger->error('BAK0353 Error pruning iSCSI targets', ['exception' => $exception]);
        }

        $this->logger->debug('ENC0102 Removing .datto files...');
        $this->removeDattoFiles();
    }

    /**
     * Removes agent .datto files.
     */
    private function removeDattoFiles()
    {
        foreach (array_keys($this->volumes) as $volume) {
            $imagePath = $this->agentPath . "/" . $volume . ".datto";
            if ($this->filesystem->exists($imagePath)) {
                if ($this->filesystem->unlink($imagePath)) {
                    $this->logger->debug('ISC1039 Temporary .datto file removed', ['imagePath' => $imagePath]);
                } else {
                    $this->logger->error('ISC1040 Error removing .datto file', ['imagePath' => $imagePath]);
                }
            }
        }
    }
}
