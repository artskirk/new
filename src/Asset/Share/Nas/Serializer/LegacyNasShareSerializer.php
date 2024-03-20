<?php

namespace Datto\Asset\Share\Nas\Serializer;

use Datto\Afp\AfpVolumeManager;
use Datto\AppKernel;
use Datto\Asset\AssetType;
use Datto\Asset\Serializer\LegacyEmailAddressSettingsSerializer;
use Datto\Asset\Serializer\LegacyLastErrorSerializer;
use Datto\Asset\Serializer\LegacyLocalSettingsSerializer;
use Datto\Asset\Serializer\LegacyOffsiteSettingsSerializer;
use Datto\Asset\Serializer\OffsiteTargetSerializer;
use Datto\Asset\Serializer\OriginDeviceSerializer;
use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Share\Nas\AccessSettings;
use Datto\Asset\Share\Nas\AfpSettings;
use Datto\Asset\Share\Nas\ApfsSettings;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\Nas\NfsSettings;
use Datto\Asset\Share\Nas\SftpSettings;
use Datto\Asset\Share\Nas\UserSettings;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Asset\UuidGenerator;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\ProcessFactory;
use Datto\Dataset\DatasetFactory;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Samba\UserService;
use Datto\Service\Security\FirewallService;
use Datto\Utility\Network\Zeroconf\Avahi;
use Datto\Utility\ByteUnit;
use Datto\Common\Utility\Filesystem;
use Datto\Nfs\NfsExportManager;
use Datto\Samba\SambaManager;
use Datto\Sftp\SftpManager;
use Datto\Utility\Systemd\Systemctl;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;

/**
 * Serialize and unserialize a NAS share object using the contents of the
 * legacy key files (e.g. '.agentInfo', ...)
 *
 * Unserializing:
 *   $nasShare = $serializer->unserialize(array(
 *       'agentInfo' => 'a:60:{...',
 *       'interval' => '60',
 *       'backupPause' => '',
 *       // A lot more ...
 *   ));
 *
 * Serializing:
 *   $serializedNasShareFileArray = $serializer->serialize(new NasShare(..));
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class LegacyNasShareSerializer implements Serializer, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private DatasetFactory $datasetFactory;
    private Filesystem $filesystem;
    private ProcessFactory $processFactory;
    protected LegacyLocalSettingsSerializer $localSerializer;
    protected LegacyOffsiteSettingsSerializer $offsiteSerializer;
    protected LegacyEmailAddressSettingsSerializer $emailAddressesSerializer;
    protected LegacyGrowthReportSerializer $growthReportSerializer;
    protected LegacyLastErrorSerializer $lastErrorSerializer;
    private OriginDeviceSerializer $originDeviceSerializer;
    private OffsiteTargetSerializer $offsiteTargetSerializer;
    private Systemctl $systemctl;
    private AfpVolumeManager $afpVolumeManager;
    private SambaManager $sambaManager;
    private NfsExportManager $nfsExportManager;
    private SftpManager $sftpManager;
    private Avahi $avahi;

    public function __construct(
        DatasetFactory $datasetFactory = null,
        DeviceLoggerInterface $logger = null,
        Filesystem $filesystem = null,
        ProcessFactory $processFactory = null,
        LegacyLocalSettingsSerializer $localSerializer = null,
        LegacyOffsiteSettingsSerializer $offsiteSerializer = null,
        LegacyEmailAddressSettingsSerializer $emailAddressesSerializer = null,
        LegacyGrowthReportSerializer $growthReportSerializer = null,
        LegacyLastErrorSerializer $lastErrorSerializer = null,
        OriginDeviceSerializer $originDeviceSerializer = null,
        OffsiteTargetSerializer $offsiteTargetSerializer = null,
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
        $this->growthReportSerializer = $growthReportSerializer ?? new LegacyGrowthReportSerializer();
        $this->lastErrorSerializer = $lastErrorSerializer ?? new LegacyLastErrorSerializer();
        $this->originDeviceSerializer = $originDeviceSerializer ?? new OriginDeviceSerializer();
        $this->offsiteTargetSerializer = $offsiteTargetSerializer ?? new OffsiteTargetSerializer();
        $this->sambaManager = $sambaManager ??
            AppKernel::getBootedInstance()->getContainer()->get(SambaManager::class);
        $this->sambaManager->setLogger($this->logger);
        $this->systemctl = $systemctl ?? new Systemctl($this->processFactory);
        $this->avahi = $avahi ?? new Avahi($this->filesystem, $this->systemctl, new UuidGenerator());
        $this->afpVolumeManager = $afpVolumeManager ??
            AppKernel::getBootedInstance()->getContainer()->get(AfpVolumeManager::class);

        $this->nfsExportManager = $nfsExportManager ?? new NfsExportManager();
        $this->sftpManager = $sftpManager ?? new SftpManager();
    }

    /**
     * Serialize a NasShare into an array of file contents.
     *
     * @param NasShare $share
     * @return array
     */
    public function serialize($share): array
    {
        $dataset = $share->getDataset();

        $fileArray = [
            'agentInfo' => serialize([
                'iscsiChapUser' => '',// N/A
                'apiVersion' => '',// N/A
                'iscsiMutualChapUser' => '',// N/A
                'hostname' => $share->getName(),
                'nfsEnabled' => $share->getNfs()->isEnabled(),
                'sftpEnabled' => $share->getSftp()->isEnabled(),
                'Volumes' => [],// N/A
                'os_name' => '',// N/A
                'version' => '',// N/A
                'iscsiBlockSize' => '',// N/A
                'name' => $share->getName(),
                'iscsiTarget' => '',// N/A
                'hostName' => $share->getName(),
                'afpEnabled' => $share->getAfp()->isEnabled(),
                'apfsEnabled' => $share->getApfs()->isEnabled(),
                'format' => $share->getFormat(),
                'type' => 'snapnas',// this will not change
                'isIscsi' => 0,// this will not change
                'shareType' => AssetType::NAS_SHARE,// this will not change
                'localUsed' => ByteUnit::BYTE()->toGiB($dataset->getUsedSize()),
                'uuid' => $share->getUuid()
            ]),
            'dateAdded' => $share->getDateAdded(),
            'shareAuth' => serialize(['user' => $share->getAccess()->getAuthorizedUser()]),
            'emails' => $this->emailAddressesSerializer->serialize($share->getEmailAddresses()),
            'growthReport' => $this->growthReportSerializer->serialize($share->getGrowthReport()),
            'lastError' => $this->lastErrorSerializer->serialize($share->getLastError()),
            'offsiteTarget' => $this->offsiteTargetSerializer->serialize($share->getOffsiteTarget())
        ];

        $fileArray = array_merge_recursive($fileArray, $this->originDeviceSerializer->serialize($share->getOriginDevice()));
        $fileArray = array_merge_recursive($fileArray, $this->localSerializer->serialize($share->getLocal()));
        $fileArray = array_merge_recursive($fileArray, $this->offsiteSerializer->serialize($share->getOffsite()));

        return $fileArray;
    }

    /**
     * Unserializes the set of share config files into a Share object. Direct share properties are unserialized
     * in this function, sub-properties are unserialized using sub-serializers.
     *
     * @param array $fileArray Array of strings containing the file contents of above listed files.
     * @return NasShare Instance of a NasShare
     */
    public function unserialize($fileArray): NasShare
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
        $format = $agentInfo['format'] ?? NasShare::DEFAULT_FORMAT;

        $afp = $this->createAfp($name, $agentInfo);
        $apfs = $this->createApfs($name, $agentInfo);
        $nfs = $this->createNfs($name, $agentInfo);
        $sftp = $this->createSftp($name, $agentInfo);
        $access = $this->createAccess($name, $afp, $apfs, $sftp, $shareAuth);

        $users = new UserSettings($name, $this->sambaManager, $afp, $sftp);

        $dateAdded = $fileArray['dateAdded'] ?? null;
        $fileArray['integrityCheckEnabled'] = false;

        $local = $this->localSerializer->unserialize($fileArray);
        $offsite = $this->offsiteSerializer->unserialize($fileArray);
        $emailAddresses = $this->emailAddressesSerializer->unserialize($fileArray);

        $growthReport = $this->growthReportSerializer->unserialize(@$fileArray['growthReport']);
        $lastError = $this->lastErrorSerializer->unserialize(@$fileArray['lastError']);

        $originDevice = $this->originDeviceSerializer->unserialize($fileArray);
        $offsiteTarget = $this->offsiteTargetSerializer->unserialize(@$fileArray['offsiteTarget']);

        $share = new NasShare(
            $name,
            $keyName,
            $dateAdded,
            $format,
            $this->datasetFactory->createZvolDataset(Share::BASE_ZFS_PATH . '/' . $keyName),
            $this->sambaManager,
            $access,
            $afp,
            $apfs,
            $nfs,
            $sftp,
            $local,
            $offsite,
            $emailAddresses,
            $users,
            $this->logger,
            $growthReport,
            $lastError,
            $uuid,
            $originDevice,
            $offsiteTarget
        );

        return $share;
    }

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

        $authenticatedUser = (isset($shareAuth['user'])) ? $shareAuth['user'] : '';

        return new AccessSettings($name, $this->logger, $this->sambaManager, $afp, $apfs, $sftp, $level, $writeLevel, $authenticatedUser);
    }

    private function createAfp($name, $agentInfo): AfpSettings
    {
        $enabled = $agentInfo['afpEnabled'] ?? AfpSettings::DEFAULT_ENABLED;

        return new AfpSettings($name, $this->logger, $this->sambaManager, $this->afpVolumeManager, $enabled);
    }

    private function createApfs($name, $agentInfo): ApfsSettings
    {
        $enabled = $agentInfo['apfsEnabled'] ?? ApfsSettings::DEFAULT_ENABLED;

        return new ApfsSettings($name, $this->logger, $this->sambaManager, $this->filesystem, $this->avahi, $enabled);
    }

    private function createNfs($name, $agentInfo): NfsSettings
    {
        $enabled = $agentInfo['nfsEnabled'] ?? NfsSettings::DEFAULT_ENABLED;

        return new NfsSettings($name, $this->sambaManager, $this->nfsExportManager, $enabled);
    }

    private function createSftp($name, $agentInfo): SftpSettings
    {
        $enabled = $agentInfo['sftpEnabled'] ?? SftpSettings::DEFAULT_ENABLED;

        return new SftpSettings($name, $this->sambaManager, $this->sftpManager, $enabled);
    }
}
