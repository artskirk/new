<?php

namespace Datto\Asset\Share\Nas;

use Datto\Afp\AfpVolumeManager;
use Datto\Asset\EmailAddressSettings;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Asset\UuidGenerator;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Dataset\DatasetFactory;
use Datto\Log\DeviceLoggerInterface;
use Datto\Nfs\NfsExportManager;
use Datto\Resource\DateTimeService;
use Datto\Samba\SambaManager;
use Datto\Sftp\SftpManager;
use Datto\Utility\Network\Zeroconf\Avahi;

/**
 * Builder for the NasShare class. The builder uses sensible defaults, but
 * can override all of the NasShare properties and sub-settings objects.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class NasShareBuilder
{
    // Asset
    private string $name;
    private ?string $keyName;
    private ?int $dateAdded;
    private LocalSettings $local;
    private OffsiteSettings $offsite;
    private EmailAddressSettings $emailAddresses;
    private UuidGenerator $uuidGenerator;
    private string $uuid;
    private OriginDevice $originDevice;
    private ?string $offsiteTarget;

    // Share
    private string $format;

    // NasShare
    private SambaManager $sambaManager;
    private AccessSettings $access;
    private AfpSettings $afp;
    private ApfsSettings $apfs;
    private NfsSettings $nfs;
    private SftpSettings $sftp;
    private UserSettings $users;
    private GrowthReportSettings $growthReport;
    private ?LastErrorAlert $lastError;
    private DeviceLoggerInterface $logger;
    private DatasetFactory $datasetFactory;
    private ProcessFactory $processFactory;

    public function __construct(
        string $name,
        DeviceLoggerInterface $logger,
        DatasetFactory $datasetFactory,
        ProcessFactory $processFactory,
        DateTimeService $dateTimeService,
        Avahi $avahi,
        SambaManager $sambaManager,
        EmailAddressSettings $emailAddresses,
        UuidGenerator $uuidGenerator,
        GrowthReportSettings $growthReportSettings,
        OriginDevice $originDevice,
        AfpVolumeManager $afpVolumeManager,
        Filesystem $filesystem,
        NfsExportManager $nfsExportManager,
        SftpManager $sftpManager
    ) {
        $this->name = $name;
        $this->keyName = null;
        $this->offsiteTarget = null;
        $this->logger = $logger;
        $this->datasetFactory = $datasetFactory;
        $this->processFactory = $processFactory;

        $this->dateAdded = $dateTimeService->getTime();
        $this->offsite = new OffsiteSettings();
        $this->emailAddresses = $emailAddresses;
        $this->uuidGenerator = $uuidGenerator;
        $this->uuid = '';
        $this->local = new LocalSettings($name);

        $this->format = NasShare::DEFAULT_FORMAT;

        $this->sambaManager = $sambaManager;
        $this->afp = new AfpSettings($name, $this->logger, $this->sambaManager, $afpVolumeManager);
        $this->apfs = new ApfsSettings($name, $this->logger, $this->sambaManager, $filesystem, $avahi);
        $this->nfs = new NfsSettings($name, $this->sambaManager, $nfsExportManager);
        $this->sftp = new SftpSettings($name, $this->sambaManager, $sftpManager);
        $this->access = new AccessSettings($name, $this->logger, $this->sambaManager, $this->afp, $this->apfs, $this->sftp);
        $this->users = new UserSettings($name, $this->sambaManager, $this->afp, $this->sftp);
        $this->growthReport = $growthReportSettings;
        $this->lastError = null;
        $this->originDevice = $originDevice;
    }

    public function dateAdded($dateAdded): NasShareBuilder
    {
        $this->dateAdded = $dateAdded;
        return $this;
    }

    public function format($format): NasShareBuilder
    {
        $this->format = $format;
        return $this;
    }

    public function samba(SambaManager $samba): NasShareBuilder
    {
        $this->sambaManager = $samba;
        return $this;
    }

    public function access(AccessSettings $access): NasShareBuilder
    {
        $this->access = $access;
        return $this;
    }

    public function afp(AfpSettings $afp): NasShareBuilder
    {
        $this->afp = $afp;
        return $this;
    }

    public function apfs(ApfsSettings $apfs): NasShareBuilder
    {
        $this->apfs = $apfs;
        return $this;
    }

    public function nfs(NfsSettings $nfs): NasShareBuilder
    {
        $this->nfs = $nfs;
        return $this;
    }

    public function sftp(SftpSettings $sftp): NasShareBuilder
    {
        $this->sftp = $sftp;
        return $this;
    }

    public function local(LocalSettings $local): NasShareBuilder
    {
        $this->local = $local;
        return $this;
    }

    public function offsite(OffsiteSettings $offsite): NasShareBuilder
    {
        $this->offsite = $offsite;
        return $this;
    }

    public function emailAddresses(EmailAddressSettings $emailAddresses): NasShareBuilder
    {
        $this->emailAddresses = $emailAddresses;
        return $this;
    }

    public function users(UserSettings $users): NasShareBuilder
    {
        $this->users = $users;
        return $this;
    }

    public function growthReport(GrowthReportSettings $growthReport): NasShareBuilder
    {
        $this->growthReport = $growthReport;
        return $this;
    }

    public function lastError(?LastErrorAlert $lastError): NasShareBuilder
    {
        $this->lastError = $lastError;
        return $this;
    }

    public function uuid($uuid): NasShareBuilder
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function originDevice($originDevice): NasShareBuilder
    {
        $this->originDevice = $originDevice;
        return $this;
    }

    public function keyName(string $keyName): NasShareBuilder
    {
        $this->keyName = $keyName;
        return $this;
    }

    public function offsiteTarget($offsiteTarget): NasShareBuilder
    {
        $this->offsiteTarget = $offsiteTarget;
        return $this;
    }

    /**
     * Build and return a new NasShare object
     */
    public function build(): NasShare
    {
        // Generate new UUID if one has not been given
        if (!$this->uuid) {
            $this->uuid = $this->uuidGenerator->get();
        }

        if (!$this->keyName) {
            $this->keyName = $this->uuid;
        }

        // FIXME integrity check does not belong in local settings since it does apply to shares
        $this->local->setIntegrityCheckEnabled(false);
        $share = new NasShare(
            $this->name,
            $this->keyName,
            $this->dateAdded,
            $this->format,
            $this->datasetFactory->createZvolDataset(Share::BASE_ZFS_PATH . '/' . $this->keyName),
            $this->sambaManager,
            $this->access,
            $this->afp,
            $this->apfs,
            $this->nfs,
            $this->sftp,
            $this->local,
            $this->offsite,
            $this->emailAddresses,
            $this->users,
            $this->logger,
            $this->growthReport,
            $this->lastError,
            $this->uuid,
            $this->originDevice,
            $this->offsiteTarget
        );

        return $share;
    }
}
