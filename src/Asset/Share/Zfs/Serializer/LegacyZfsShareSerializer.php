<?php

namespace Datto\Asset\Share\Zfs\Serializer;

use Datto\Afp\AfpVolumeManager;
use Datto\AppKernel;
use Datto\Asset\AssetType;
use Datto\Asset\Serializer\LegacyEmailAddressSettingsSerializer;
use Datto\Asset\Serializer\LegacyLastErrorSerializer;
use Datto\Asset\Serializer\LegacyLocalSettingsSerializer;
use Datto\Asset\Serializer\LegacyOffsiteSettingsSerializer;
use Datto\Asset\Serializer\OriginDeviceSerializer;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Share\Nas\AccessSettings;
use Datto\Asset\Share\Nas\AfpSettings;
use Datto\Asset\Share\Nas\ApfsSettings;
use Datto\Asset\Share\Nas\NfsSettings;
use Datto\Asset\Share\Nas\SftpSettings;
use Datto\Asset\Share\Nas\UserSettings;
use Datto\Asset\Share\ShareService;
use Datto\Asset\Share\Zfs\ZfsShare;
use Datto\Asset\UuidGenerator;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\ProcessFactory;
use Datto\Dataset\DatasetFactory;
use Datto\Dataset\ZFS_Dataset;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\LoggerFactory;
use Datto\Nfs\NfsExportManager;
use Datto\Common\Utility\Filesystem;
use Datto\Samba\SambaManager;
use Datto\Samba\UserService;
use Datto\Service\Security\FirewallService;
use Datto\Sftp\SftpManager;
use Datto\Utility\Network\Zeroconf\Avahi;
use Datto\Utility\ByteUnit;
use Datto\Utility\Systemd\Systemctl;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;

/**
 * Serialize and unserialize a ZFS share object using the contents of the
 * legacy key files (e.g. '.agentInfo', ...)
 *
 * @author Andrew Cope <acope@datto.com>
 */
class LegacyZfsShareSerializer implements Serializer, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private Filesystem $filesystem;
    private ProcessFactory $processFactory;
    protected LegacyLocalSettingsSerializer $localSerializer;
    protected LegacyOffsiteSettingsSerializer $offsiteSerializer;
    protected LegacyEmailAddressSettingsSerializer $emailAddressesSerializer;
    protected LegacyLastErrorSerializer $lastErrorSerializer;
    private OriginDeviceSerializer $originDeviceSerializer;
    private DatasetFactory $datasetFactory;
    private Systemctl $systemctl;
    private AfpVolumeManager $afpVolumeManager;
    private SambaManager $sambaManager;
    private NfsExportManager $nfsExportManager;
    private SftpManager $sftpManager;
    private ShareService $shareService;
    private Avahi $avahi;

    public function __construct(
        DatasetFactory $datasetFactory = null,
        DeviceLoggerInterface $logger = null,
        Filesystem $filesystem = null,
        ProcessFactory $processFactory = null,
        Serializer $localSerializer = null,
        Serializer $offsiteSerializer = null,
        Serializer $emailAddressesSerializer = null,
        Serializer $lastErrorSerializer = null,
        Serializer $originDeviceSerializer = null,
        SambaManager $sambaManager = null,
        AfpVolumeManager $afpVolumeManager = null,
        Systemctl $systemctl = null,
        NfsExportManager $nfsExportManager = null,
        SftpManager $sftpManager = null,
        Avahi $avahi = null
    ) {
        $this->datasetFactory = $datasetFactory ?? new DatasetFactory();
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
        $this->processFactory = $processFactory ?? new ProcessFactory();
        $this->filesystem = $filesystem ?? new Filesystem($this->processFactory);
        $this->localSerializer = $localSerializer ?? new LegacyLocalSettingsSerializer();
        $this->offsiteSerializer = $offsiteSerializer ?? new LegacyOffsiteSettingsSerializer();
        $this->emailAddressesSerializer = $emailAddressesSerializer ?? new LegacyEmailAddressSettingsSerializer();
        $this->lastErrorSerializer = $lastErrorSerializer ?? new LegacyLastErrorSerializer();
        $this->originDeviceSerializer = $originDeviceSerializer ?? new OriginDeviceSerializer();
        $this->sambaManager = $sambaManager ?? AppKernel::getBootedInstance()->getContainer()->get(SambaManager::class);
        $this->sambaManager->setLogger($this->logger);
        $this->avahi = $avahi ?? new Avahi($this->filesystem, new Systemctl(), new UuidGenerator());
        $this->systemctl = $systemctl ?? new Systemctl($this->processFactory);
        $this->afpVolumeManager = $afpVolumeManager ??
            AppKernel::getBootedInstance()->getContainer()->get(AfpVolumeManager::class);
        $this->nfsExportManager = $nfsExportManager ?? new NfsExportManager();
        $this->sftpManager = $sftpManager ?? new SftpManager();
    }

    /**
     * @param ZfsShare $share
     * @return array
     */
    public function serialize($share): array
    {
        /** @var ZFS_Dataset $dataset */
        $dataset = $share->getDataset();

        $fileArray = [
            'agentInfo' => serialize([
                'iscsiChapUser' => '',// N/A
                'apiVersion' => '',// N/A
                'iscsiMutualChapUser' => '',// N/A
                'hostname' => $share->getName(),
                'afpEnabled' => $share->getAfp()->isEnabled(),
                'apfsEnabled' => $share->getApfs()->isEnabled(),
                'nfsEnabled' => $share->getNfs()->isEnabled(),
                'sftpEnabled' => false,
                'Volumes' => [],// N/A
                'os_name' => '',// N/A
                'version' => '',// N/A
                'iscsiBlockSize' => '',// N/A
                'name' => $share->getName(),
                'iscsiTarget' => '',// N/A
                'hostName' => $share->getName(),
                'type' => 'snapnas',// this will not change
                'isIscsi' => 0,// this will not change
                'shareType' => AssetType::ZFS_SHARE,// this will not change
                'localUsed' => ByteUnit::BYTE()->toGiB($dataset->getUsedSize()),
                'uuid' => $share->getUuid(),
            ]),
            'dateAdded' => $share->getDateAdded(),
            'shareAuth' => serialize(['user' => $share->getAccess()->getAuthorizedUser()]),
            'emails' => $this->emailAddressesSerializer->serialize($share->getEmailAddresses()),
            'lastError' => $this->lastErrorSerializer->serialize($share->getLastError())
        ];

        $fileArray = array_merge_recursive($fileArray, $this->originDeviceSerializer->serialize($share->getOriginDevice()));
        $fileArray = array_merge_recursive($fileArray, $this->localSerializer->serialize($share->getLocal()));
        $fileArray = array_merge_recursive($fileArray, $this->offsiteSerializer->serialize($share->getOffsite()));

        return $fileArray;
    }

    public function unserialize($fileArray): ZfsShare
    {
        if (!isset($fileArray['agentInfo']) || !$fileArray['agentInfo']) {
            throw new InvalidArgumentException('Cannot read "agentInfo" contents.');
        }

        $agentInfo = @unserialize($fileArray['agentInfo'], ['allowed_classes' => false]);
        $shareAuth = @unserialize($fileArray['shareAuth'], ['allowed_classes' => false]);

        if (!is_array($shareAuth)) {
            $shareAuth = [];
        }

        if (!isset($agentInfo['name'])) {
            throw new InvalidArgumentException('Cannot read "name" attribute for share.');
        }

        $name = $agentInfo['name'];
        $keyName = $fileArray['keyName'];
        $uuid = $agentInfo['uuid'] ?? null;
        $dateAdded = $fileArray['dateAdded'] ?? null;

        $afp = $this->createAfp($name, $agentInfo);
        $apfs = $this->createApfs($name, $agentInfo);
        $nfs = $this->createNfs($name, $agentInfo);
        $sftp = $this->createSftp($name, $agentInfo);
        $accessSettings = $this->createAccess($name, $afp, $apfs, $sftp, $shareAuth);
        $userSettings = new UserSettings($name, $this->sambaManager, $afp, $sftp);

        $local = $this->localSerializer->unserialize($fileArray);
        $offsite = $this->offsiteSerializer->unserialize($fileArray);
        $emailAddresses = $this->emailAddressesSerializer->unserialize($fileArray);

        $lastError = $this->lastErrorSerializer->unserialize(@$fileArray['lastError']);
        $originDevice = $this->originDeviceSerializer->unserialize($fileArray);

        $dataset = $this->datasetFactory->createZfsDataset('homePool/home/' . $keyName);

        return new ZfsShare(
            $name,
            $keyName,
            $dateAdded,
            $dataset,
            $local,
            $offsite,
            $emailAddresses,
            $accessSettings,
            $userSettings,
            $afp,
            $apfs,
            $nfs,
            $sftp,
            $this->sambaManager,
            $this->logger,
            $lastError,
            $uuid,
            $originDevice
        );
    }

    /**
     * @return AccessSettings share object based on the array
     */
    private function createAccess(
        string $name,
        AfpSettings $afp,
        ApfsSettings $apfs,
        SftpSettings $sftp,
        array $shareAuth = []
    ): AccessSettings {
        $sambaShare = $this->sambaManager->getShareByName($name);

        if ($sambaShare) {
            $level = ($sambaShare->isPublic)
                ? AccessSettings::ACCESS_LEVEL_PUBLIC
                : AccessSettings::ACCESS_LEVEL_PRIVATE;

            $writeLevel = ($sambaShare->aclMode === AccessSettings::ACL_MODE_CREATOR)
                ? AccessSettings::WRITE_ACCESS_LEVEL_CREATOR
                : AccessSettings::WRITE_ACCESS_LEVEL_ALL;
        } else {
            $level = AccessSettings::DEFAULT_ACCESS_LEVEL;
            $writeLevel = AccessSettings::DEFAULT_WRITE_ACCESS_LEVEL;
        }

        $authenticatedUser = $shareAuth['user'] ?? '';

        return new AccessSettings($name, $this->logger, $this->sambaManager, $afp, $apfs, $sftp, $level, $writeLevel, $authenticatedUser);
    }

    /**
     * @param string $name
     * @param array $agentInfo
     * @return AfpSettings
     */
    private function createAfp(string $name, array $agentInfo): AfpSettings
    {
        $enabled = $agentInfo['afpEnabled'] ?? AfpSettings::DEFAULT_ENABLED;

        return new AfpSettings($name, $this->logger, $this->sambaManager, $this->afpVolumeManager, $enabled);
    }

    /**
     * @param string $name
     * @param array $agentInfo
     * @return ApfsSettings
     */
    private function createApfs(string $name, array $agentInfo): ApfsSettings
    {
        $enabled = $agentInfo['apfsEnabled'] ?? ApfsSettings::DEFAULT_ENABLED;

        return new ApfsSettings($name, $this->logger, $this->sambaManager, $this->filesystem, $this->avahi, $enabled);
    }

    /**
     * @param string $name
     * @param array $agentInfo
     * @return NfsSettings
     */
    private function createNfs(string $name, array $agentInfo): NfsSettings
    {
        $enabled = $agentInfo['nfsEnabled'] ?? NfsSettings::DEFAULT_ENABLED;

        return new NfsSettings($name, $this->sambaManager, $this->nfsExportManager, $enabled);
    }

    /**
     * @param string $name
     * @param array $agentInfo
     * @return SftpSettings
     */
    private function createSftp(string $name, array $agentInfo): SftpSettings
    {
        $enabled = $agentInfo['sftpEnabled'] ?? SftpSettings::DEFAULT_ENABLED;

        return new SftpSettings($name, $this->sambaManager, $this->sftpManager, $enabled);
    }
}
