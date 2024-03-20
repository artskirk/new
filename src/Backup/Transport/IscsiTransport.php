<?php

namespace Datto\Backup\Transport;

use Datto\Iscsi\IscsiTarget;
use Datto\Iscsi\UserType;
use Datto\Security\PasswordGenerator;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Handles Iscsi data transfer from the agent to the device.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class IscsiTransport extends BackupTransport
{
    const PORT = 3260;
    const CHAP_USERNAME_LENGTH = 8;
    const CHAP_PASSWORD_LENGTH = 14;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var IscsiTarget */
    private $iscsiTarget;

    /** @var string */
    private $iscsiTargetName;

    /** @var PasswordGenerator */
    private $passwordGenerator;

    /** @var string */
    private $chapUsername;

    /** @var string */
    private $chapPassword;

    /** @var array */
    private $volumes;

    /**
     * @param string $assetKeyName
     * @param DeviceLoggerInterface $logger
     * @param IscsiTarget|null $iscsiTarget
     * @param PasswordGenerator|null $passwordGenerator
     */
    public function __construct(
        string $assetKeyName,
        DeviceLoggerInterface $logger,
        IscsiTarget $iscsiTarget = null,
        PasswordGenerator $passwordGenerator = null
    ) {
        $this->logger = $logger;
        $this->iscsiTarget = $iscsiTarget ?: new IscsiTarget();
        $this->iscsiTargetName = $this->iscsiTarget->makeTargetNameTemp($assetKeyName);
        $this->passwordGenerator = $passwordGenerator ?: new PasswordGenerator();
        $this->chapUsername = $this->passwordGenerator->generate(self::CHAP_USERNAME_LENGTH);
        $this->chapPassword = $this->passwordGenerator->generate(self::CHAP_PASSWORD_LENGTH);
    }

    /**
     * @inheritdoc
     */
    public function setup(array $imageLoopsOrFiles, array $checksumFiles, array $allVolumes)
    {
        $this->initialize();

        foreach ($imageLoopsOrFiles as $guid => $loopDev) {
            $checksumFileForLun = $checksumFiles[$guid];
            $this->addVolume($guid, $loopDev, $checksumFileForLun);
        }
    }

    /**
     * @inheritdoc
     */
    public function getQualifiedName(): string
    {
        return $this->iscsiTargetName;
    }

    /**
     * @inheritdoc
     */
    public function getPort(): int
    {
        return self::PORT;
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
        $this->logger->debug('IBT0004 Removing iSCSI target: '  . $this->iscsiTargetName);
        try {
            if ($this->iscsiTarget->doesTargetExist($this->iscsiTargetName)) {
                $this->iscsiTarget->deleteTarget($this->iscsiTargetName);
                $this->logger->debug('IBT0005 iSCSI target removed successfully.');
            }
        } catch (Throwable $exception) {
            $this->logger->critical(
                'IBT0006 Unexpected error removing iSCSI target. iSCSI WILL NOT WORK',
                ['exception' => $exception]
            );
            throw $exception;
        }
    }

    /**
     * Initialize the iSCSI target
     */
    private function initialize()
    {
        $this->volumes = [];

        $this->logger->debug('IBT0001 Creating iSCSI target ' . $this->iscsiTargetName);
        $this->iscsiTarget->createTarget($this->iscsiTargetName);
        $this->logger->debug('IBT0007 Adding CHAP credentials to iSCSI target ' . $this->iscsiTargetName);
        $this->iscsiTarget->addTargetChapUser(
            $this->iscsiTargetName,
            UserType::INCOMING(),
            $this->chapUsername,
            $this->chapPassword
        );
    }

    /**
     * Add volume information and create iSCSI targets for volume and checksum loops / files.
     *
     * @param string $volumeGuid
     * @param string $loopDev
     * @param string $checksumFileForLun
     */
    private function addVolume(string $volumeGuid, string $loopDev, string $checksumFileForLun)
    {
        $this->logger->debug("IBT0002 Creating iscsi lun for backup image $loopDev for volume $volumeGuid");
        $lunId = $this->iscsiTarget->addLun($this->iscsiTargetName, $loopDev, false, true);

        $this->logger->debug("IBT0003 Creating lun for checksum file $checksumFileForLun for volume $volumeGuid");
        $checksumLunId = $this->iscsiTarget->addLun($this->iscsiTargetName, $checksumFileForLun, false, true);

        $this->volumes[$volumeGuid] = [
            "lun" => $lunId,
            "lunChecksum" => $checksumLunId,
            "username" => $this->chapUsername,
            "password" => $this->chapPassword
        ];
    }
}
