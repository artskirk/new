<?php

namespace Datto\Asset\Share\ExternalNas;

use Datto\Asset\EmailAddressSettings;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\OriginDevice;
use Datto\Asset\Share\Share;
use Datto\Asset\UuidGenerator;
use Datto\Dataset\DatasetFactory;
use Datto\Log\DeviceLoggerInterface;
use Datto\System\SambaMount;

/**
 * Builder for the external nas class. The builder uses sensible defaults, but
 * can override all of the ExternalNasShare properties and sub-settings objects.
 *
 * @author Jeffrey Knapp <jkanpp@datto.com>
 */
class ExternalNasShareBuilder
{
    private string $name;
    private int $dateAdded;
    private OffsiteSettings $offsite;
    private EmailAddressSettings $emailAddresses;
    private UuidGenerator $uuidGenerator;
    private OriginDevice $originDevice;
    private string $format;
    private SambaMount $sambaMount;
    private bool $backupAcls;
    private DeviceLoggerInterface $logger;
    private DatasetFactory $datasetFactory;

    private ?string $uuid = null;
    private ?string $keyName = null;
    private ?LocalSettings $local = null;
    private ?string $offsiteTarget = null;
    private ?LastErrorAlert $lastError = null;
    private ?string $smbVersion = null;
    private ?string $ntlmAuthentication = null;

    public function __construct(
        string $name,
        SambaMount $sambaMount,
        DeviceLoggerInterface $logger,
        ?DatasetFactory $datasetFactory = null
    ) {
        $this->name = $name;
        $this->sambaMount = $sambaMount;
        $this->logger = $logger;
        $this->datasetFactory = $datasetFactory ?? new DatasetFactory();

        $this->dateAdded = time();
        $this->offsite = new OffsiteSettings();
        $this->emailAddresses = new EmailAddressSettings();
        $this->uuidGenerator = new UuidGenerator();

        $this->format = ExternalNasShare::DEFAULT_FORMAT;

        $this->lastError = null;

        $this->backupAcls = false;
        $this->originDevice = new OriginDevice();
    }

    /**
     * @param int $dateAdded Epoch timestamp
     */
    public function dateAdded(int $dateAdded): ExternalNasShareBuilder
    {
        $this->dateAdded = $dateAdded;
        return $this;
    }

    public function format(string $format): ExternalNasShareBuilder
    {
        $this->format = $format;
        return $this;
    }

    public function local(LocalSettings $local): ExternalNasShareBuilder
    {
        $this->local = $local;
        return $this;
    }

    public function offsite(OffsiteSettings $offsite): ExternalNasShareBuilder
    {
        $this->offsite = $offsite;
        return $this;
    }

    public function emailAddresses(EmailAddressSettings $emailAddresses): ExternalNasShareBuilder
    {
        $this->emailAddresses = $emailAddresses;
        return $this;
    }

    public function lastError(?LastErrorAlert $lastError = null): ExternalNasShareBuilder
    {
        $this->lastError = $lastError;
        return $this;
    }

    public function uuid(?string $uuid): ExternalNasShareBuilder
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function backupAcls(bool $enabled): self
    {
        $this->backupAcls = $enabled;
        return $this;
    }

    public function keyName(string $keyName): ExternalNasShareBuilder
    {
        $this->keyName = $keyName;
        return $this;
    }

    public function originDevice(OriginDevice $originDevice): ExternalNasShareBuilder
    {
        $this->originDevice = $originDevice;
        return $this;
    }

    public function offsiteTarget(string $offsiteTarget): ExternalNasShareBuilder
    {
        $this->offsiteTarget = $offsiteTarget;
        return $this;
    }

    public function smbVersion(?string $smbVersion): ExternalNasShareBuilder
    {
        $this->smbVersion = $smbVersion;
        return $this;
    }

    public function ntlmAuthentication(?string $ntlmAuthentication): ExternalNasShareBuilder
    {
        $this->ntlmAuthentication = $ntlmAuthentication;
        return $this;
    }

    /**
     * Build and return a new NasShare object
     * @return ExternalNasShare
     */
    public function build(): ExternalNasShare
    {
        // Generate new UUID if one has not been given
        if (!$this->uuid) {
            $this->uuid = $this->uuidGenerator->get();
        }

        if ($this->backupAcls) {
            $this->format(ExternalNasShare::FORMAT_NTFS);
        }

        if (!$this->keyName) {
            $this->keyName = $this->uuid;
        }

        $local = $this->local ?? new LocalSettings($this->keyName);

        // FIXME integrity check does not belong in local settings since it does apply to shares
        $local->setIntegrityCheckEnabled(false);
        $share = new ExternalNasShare(
            $this->name,
            $this->keyName,
            $this->dateAdded,
            $this->format,
            $this->datasetFactory->createZvolDataset(Share::BASE_ZFS_PATH . '/' . $this->keyName),
            $local,
            $this->offsite,
            $this->emailAddresses,
            $this->sambaMount,
            $this->logger,
            $this->lastError,
            $this->uuid,
            $this->backupAcls,
            $this->originDevice,
            $this->offsiteTarget,
            $this->ntlmAuthentication,
            $this->smbVersion
        );

        return $share;
    }
}
