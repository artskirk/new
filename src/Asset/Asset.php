<?php

namespace Datto\Asset;

use Datto\Dataset\Dataset;

/**
 * An asset represents an entity that can be snapshotted and backed up via ZFS, typically a share or an agent.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
abstract class Asset
{
    const BASE_MOUNT_PATH = '/datto/mounts';

    /** @var string name of agentInfo file */
    protected $keyName;

    /** @var string Name of the asset */
    protected $name;

    /** @var string Type of the asset */
    protected $type;

    /** @var int UnixTimeStamp the asset was added */
    protected $dateAdded;

    /** @var LocalSettings Local settings and functions of this asset */
    protected $local;

    /** @var OffsiteSettings Offsite settings and functions of this asset */
    protected $offsite;

    /** @var EmailAddressSettings */
    protected $emailAddresses;

    /** @var LastErrorAlert */
    protected $lastError;

    /** @var ScriptSettings */
    protected $scriptSettings;

    /** @var VerificationSchedule */
    protected $verificationSchedule;

    /** @var string */
    protected $uuid;

    /** @var OriginDevice */
    protected $originDevice;

    /** @var string|null */
    protected $offsiteTarget;

    /** @var BackupConstraints|null */
    protected $backupConstraints;

    public function __construct(
        $name,
        $keyName,
        $type,
        $dateAdded,
        LocalSettings $local,
        OffsiteSettings $offsite,
        EmailAddressSettings $emailAddresses,
        LastErrorAlert $lastError = null,
        ScriptSettings $scriptSettings = null,
        VerificationSchedule $verificationSchedule = null,
        $uuid = '',
        OriginDevice $originDevice = null,
        string $offsiteTarget = null,
        BackupConstraints $backupConstraints = null
    ) {
        $this->name = $name;
        $this->keyName = $keyName;
        $this->type = $type;
        $this->dateAdded = $dateAdded;
        $this->local = $local;
        $this->offsite = $offsite;
        $this->emailAddresses = $emailAddresses;
        $this->lastError = $lastError;
        $this->scriptSettings = $scriptSettings ?: new ScriptSettings($name);
        $this->verificationSchedule = $verificationSchedule ?: new VerificationSchedule();
        $this->uuid = $uuid;
        $this->originDevice = $originDevice ?: new OriginDevice();
        $this->offsiteTarget = $offsiteTarget;
        $this->backupConstraints = $backupConstraints;
    }

    /**
     * Check if this asset is of the given type (as per AssetType).
     *
     * @param string $type Asset type, e.g. AssetType::SHARE or AssetType::WINDOWS_AGENT
     * @return bool
     */
    public function isType($type)
    {
        $className = AssetType::toClassName($type);
        return $this instanceof $className;
    }

    /**
     * @return string asset key name.
     */
    public function getKeyName()
    {
        return $this->keyName;
    }

    /**
     * @return string asset name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string asset type
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return int UnixTimeStamp the asset was added
     */
    public function getDateAdded()
    {
        return $this->dateAdded;
    }

    /**
     * @return LocalSettings
     */
    public function getLocal()
    {
        return $this->local;
    }

    /**
     * @return OffsiteSettings
     */
    public function getOffsite()
    {
        return $this->offsite;
    }

    /**
     * @return EmailAddressSettings
     */
    public function getEmailAddresses()
    {
        return $this->emailAddresses;
    }

    /**
     * @return LastErrorAlert|null $lastError
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Clear last error
     */
    public function clearLastError(): void
    {
        $this->lastError = null;
    }

    /**
     * @return ScriptSettings
     */
    public function getScriptSettings()
    {
        return $this->scriptSettings;
    }

    /**
     * @return VerificationSchedule
     */
    public function getVerificationSchedule()
    {
        return $this->verificationSchedule;
    }

    /**
     * Save any extra data that is maintained outside the asset structure such as iscsi targets or samba settings
     * This is called right before saving the asset
     */
    public function commit()
    {
    }

    /**
     * Copy the configuration from the passed share to this one.
     *
     * @param Asset $from
     */
    public function copyFrom(Asset $from)
    {
        $this->local->copyFrom($from->getLocal());
        $this->offsite->copyFrom($from->getOffsite());
        $this->emailAddresses->copyFrom($from->getEmailAddresses());
        $this->offsiteTarget = $from->offsiteTarget;
    }

    /**
     * @return string
     * @deprecated Do not use this to identify an agent on disk. Use getKeyName() instead.
     *   This should not be used to identify an agent because uuids did not always exist and there are old assets in
     *   the fleet where the keyName is equal to the hostname (or share name) instead of the uuid. For example, the zfs
     *   dataset for these can look like this: "homePool/home/agents/myHostname.datto.lan" and the key files look like
     *   this: "/datto/config/keys/my-Hostname.datto.lan.agentInfo".
     *   Note that this is not actually deprecated, it's just used as a reminder.
     */
    public function getUuid()
    {
        return $this->uuid;
    }

    /**
     * @param $uuid
     */
    public function setUuid($uuid): void
    {
        $this->uuid = $uuid;
    }

    /**
     * @return OriginDevice
     */
    public function getOriginDevice()
    {
        return $this->originDevice;
    }

    /**
     * @param OriginDevice $originDevice
     */
    public function setOriginDevice(OriginDevice $originDevice): void
    {
        $this->originDevice = $originDevice;
    }

    /**
     * @return string|null
     */
    public function getOffsiteTarget()
    {
        return $this->offsiteTarget;
    }

    /**
     * @param string $offsiteTarget
     */
    public function setOffsiteTarget(string $offsiteTarget): void
    {
        $this->offsiteTarget = $offsiteTarget;
    }

    /**
     * @return BackupConstraints|null
     */
    public function getBackupConstraints(): ?BackupConstraints
    {
        return $this->backupConstraints;
    }

    /**
     * @param BackupConstraints|null $backupConstraints
     */
    public function setBackupConstraints(?BackupConstraints $backupConstraints): void
    {
        $this->backupConstraints = $backupConstraints;
    }

    /**
     * Check whether the asset has a dataset and if it has any recovery points.
     *
     * @return bool
     */
    public function hasDatasetAndPoints(): bool
    {
        return $this->getDataset()->exists() && $this->getLocal()->getRecoveryPoints()->size() > 0;
    }

    public function supportsDiffMerge(): bool
    {
        return false;
    }

    /**
     * @return Dataset
     */
    abstract public function getDataset();

    /**
     * @return string
     */
    abstract public function getDisplayName();

    /**
     * Get the name that was used to pair the asset.
     *
     * @return string
     */
    abstract public function getPairName();
}
