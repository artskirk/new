<?php

namespace Datto\Backup\Transport;

use Datto\Mercury\MercuryFtpTarget;
use Datto\Mercury\MercuryFTPTLSService;
use Datto\Mercury\MercuryTargetDoesNotExistException;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Handles Mercury FTP data transfer from the agent to the device.
 *
 * @author Christopher Bitler <cbitler@datto.com>
 */
class MercuryFtpTransport extends BackupTransport
{
    private const PORT = 3262;

    private DeviceLoggerInterface $logger;
    private MercuryFtpTarget $target;
    private MercuryFTPTLSService $mercuryFTPTLSService;
    private string $targetName;
    private array $volumes;
    private int $lunPathsIndex;
    private array $lunPaths;
    private string $password;

    /**
     * @param string $assetKeyName
     * @param DeviceLoggerInterface $logger
     * @param MercuryFtpTarget $target
     * @param string|null $password
     */
    public function __construct(
        string $assetKeyName,
        DeviceLoggerInterface $logger,
        MercuryFtpTarget $target,
        MercuryFTPTLSService $mercuryFTPTLSService,
        string $password = null
    ) {
        $this->logger = $logger;
        $this->target = $target;
        $this->mercuryFTPTLSService = $mercuryFTPTLSService;
        $this->targetName = $this->target->makeTargetNameTemp($assetKeyName);
        $this->password = $password ?? '';
    }

    /**
     * @inheritdoc
     */
    public function setup(array $imageLoopsOrFiles, array $checksumFiles, array $allVolumes)
    {
        $this->target->startIfDead();

        $this->initialize();

        foreach ($imageLoopsOrFiles as $guid => $loopDev) {
            $checksumFileForLun = $checksumFiles[$guid];
            $this->addVolume($guid, $loopDev, $checksumFileForLun);
        }

        $this->finalize();
    }

    /**
     * @inheritdoc
     */
    public function getQualifiedName(): string
    {
        return $this->targetName;
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
        return [
            'interface' => 'mercuryftp'
        ];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        $this->logger->debug('MBT0004 Removing MercuryFTP target.', ['targetName' => $this->targetName]);
        try {
            $this->target->deleteTarget($this->targetName);
            $this->logger->debug(
                'MBT0005 Leftover MercuryFTP target cleaned successfully.',
                ['targetName' => $this->targetName]
            );
        } catch (MercuryTargetDoesNotExistException $exception) {
            $this->logger->debug(
                'MBT0006 MercuryFTP target does not exist, nothing to clean.',
                ['targetName' => $this->targetName, 'exception' => $exception]
            );
        } catch (Throwable $exception) {
            $this->logger->critical(
                'MBT0007 Unexpected error removing mercury target.',
                ['targetName' => $this->targetName, 'exception' => $exception]
            );
            throw $exception;
        }
    }

    /**
     * @inheritdoc
     */
    public function getDestinationHost(string $ipAddress, bool &$verifyCertificate): string
    {
        return $this->mercuryFTPTLSService->getMercuryFtpHost($ipAddress, $verifyCertificate);
    }

    /**
     * Initialize the Mercury settings
     */
    private function initialize()
    {
        $this->volumes = [];
        $this->lunPathsIndex = 0;
        $this->lunPaths = [];

        $this->logger->debug('MBT0001 Using mercuryFTP as data transport', ['targetName' => $this->targetName]);
    }

    /**
     * Add volume information and update Mercury settings for volume and checksum loops / files.
     *
     * @param string $volumeGuid
     * @param string $loopDev
     * @param string $checksumFileForLun
     */
    private function addVolume(string $volumeGuid, string $loopDev, string $checksumFileForLun)
    {
        $this->logger->debug(
            'MBT0002 Adding mercuryFTP lun for backup image',
            ['loopDev' => $loopDev, 'volumeGuid' => $volumeGuid]
        );

        $this->lunPaths[$this->lunPathsIndex] = $loopDev;
        $lunId = $this->lunPathsIndex;
        $this->lunPathsIndex += 1;

        $this->logger->debug(
            'MBT0003 Adding lun for checksum file',
            ['checksumFile' => $checksumFileForLun, 'volumeGuid' => $volumeGuid]
        );

        $this->lunPaths[$this->lunPathsIndex] = $checksumFileForLun;
        $checksumLunId = $this->lunPathsIndex;
        $this->lunPathsIndex += 1;

        $this->volumes[$volumeGuid] = [
            'lun' => $lunId,
            'lunChecksum' => $checksumLunId,
            'password' => $this->password
        ];
    }

    /**
     * Create the Mercury target
     */
    private function finalize()
    {
        $this->logger->debug(
            'MBT0008 Creating MercuryFTP target.',
            ['targetName' => $this->targetName, 'includingPassword' => $this->password !== '']
        );
        $this->target->createTarget($this->targetName, $this->lunPaths, $this->password);
    }
}
