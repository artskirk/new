<?php

namespace Datto\Asset\Share\Nas;

use Datto\Afp\AfpVolumeManager;
use Datto\AppKernel;
use Datto\Asset\UuidGenerator;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\Samba\SambaManager;
use Datto\Samba\SambaShare;
use Datto\Utility\Network\Zeroconf\Avahi;
use Datto\Utility\Systemd\Systemctl;

/**
 * Manages the read/write access to a NAS/Samba share.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class AccessSettings extends AbstractSettings
{
    const ACCESS_LEVEL_PUBLIC = 'public';
    const ACCESS_LEVEL_PRIVATE = 'private';

    const ACL_MODE_CREATOR = 'acl_and_mask';
    const ACL_MODE_ALL = 'no_acl';

    const WRITE_ACCESS_LEVEL_CREATOR = 'creator';
    const WRITE_ACCESS_LEVEL_ALL = 'all';

    const DEFAULT_ACCESS_LEVEL = self::ACCESS_LEVEL_PRIVATE;
    const DEFAULT_WRITE_ACCESS_LEVEL = self::WRITE_ACCESS_LEVEL_CREATOR;
    const DEFAULT_AUTHORIZED_USER = '';

    /** @var AfpSettings */
    private $afpSettings;

    /** @var SftpSettings */
    private $sftpSettings;

    /** @var string */
    private $level;

    /** @var string */
    private $writeLevel;

    /** @var string User that will be granted Samba access during share restore */
    private $authorizedUser;

    private DeviceLoggerInterface $logger;
    private ProcessFactory $processFactory;
    private ApfsSettings $apfsSettings;

    public function __construct(
        string $name,
        DeviceLoggerInterface $logger,
        SambaManager $samba,
        AfpSettings $afpSettings = null,
        ApfsSettings $apfsSettings = null,
        SftpSettings $sftpSettings = null,
        $level = self::DEFAULT_ACCESS_LEVEL,
        $writeLevel = self::DEFAULT_WRITE_ACCESS_LEVEL,
        $authorizedUser = self::DEFAULT_AUTHORIZED_USER,
        ProcessFactory $processFactory = null
    ) {
        parent::__construct($name, $samba);

        $this->logger = $logger;
        $this->processFactory = $processFactory ?? new ProcessFactory();
        if ($afpSettings === null) {
            /* @var AfpVolumeManager $afpVolumeManager */
            $afpVolumeManager = AppKernel::getBootedInstance()->getContainer()->get(AfpVolumeManager::class);
            $this->afpSettings = new AfpSettings($name, $logger, $samba, $afpVolumeManager);
        } else {
            $this->afpSettings = $afpSettings;
        }

        $this->apfsSettings = $apfsSettings ?? new ApfsSettings($name, $logger, $samba, new Filesystem($this->processFactory), new Avahi(new Filesystem($this->processFactory), new Systemctl(), new UuidGenerator()));
        $this->sftpSettings = $sftpSettings ?? new SftpSettings($name, $samba);

        $this->level = $level;
        $this->writeLevel = $writeLevel;
        $this->authorizedUser = $authorizedUser;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getWriteLevel(): string
    {
        return $this->writeLevel;
    }

    public function getAuthorizedUser(): string
    {
        return $this->authorizedUser;
    }

    public function setLevel(string $level): void
    {
        $oldLevel = $this->level;
        $this->level = $level;

        $this->logger->debug('SMB0010 Setting samba access level', ['level' => $this->level]);

        /** @var SambaShare $sambaShare */
        $sambaShare = $this->getSambaShare();
        $sambaShare->setAccess($this->level);

        $this->samba->sync();

        // When changing access level from private to public, disable AFP and SFTP as it offers no anonymous access
        $fromPrivateToPublic =
               $oldLevel === self::ACCESS_LEVEL_PRIVATE
            && $level === self::ACCESS_LEVEL_PUBLIC;

        if ($fromPrivateToPublic) {
            $this->disableAfp();
            $this->disableApfs();
            $this->disableSftp();
        }
    }

    public function setWriteLevel(string $writeLevel): void
    {
        $this->writeLevel = $writeLevel;

        $aclMode = ($this->writeLevel === self::WRITE_ACCESS_LEVEL_CREATOR) ? self::ACL_MODE_CREATOR : self::ACL_MODE_ALL;
        $this->logger->debug('SMB0011 Setting internal write access level', ['aclMode' => $aclMode]);

        /** @var SambaShare $sambaShare */
        $sambaShare = $this->getSambaShare();
        $sambaShare->changeACLMode($aclMode);

        if ($this->writeLevel == self::WRITE_ACCESS_LEVEL_ALL) {
            $process = $this->processFactory
                ->get(['chmod', '-R', '0777', $sambaShare->getPath()]);

            $process->run();
        }

        $this->samba->sync();
    }

    public function setAuthorizedUser(string $authorizedUser): void
    {
        $this->authorizedUser = $authorizedUser;
    }

    /**
     * @param AccessSettings $from
     */
    public function copyFrom(AccessSettings $from): void
    {
        $this->setLevel($from->getLevel());
        $this->setWriteLevel($from->getWriteLevel());
        $this->setAuthorizedUser($from->getAuthorizedUser());
    }

    private function disableAfp(): void
    {
        if ($this->afpSettings->isEnabled()) {
            $this->afpSettings->disable();
        }
    }

    private function disableApfs(): void
    {
        if ($this->apfsSettings->isEnabled()) {
            $this->apfsSettings->disable();
        }
    }

    private function disableSftp(): void
    {
        if ($this->sftpSettings->isEnabled()) {
            $this->sftpSettings->disable();
        }
    }
}
