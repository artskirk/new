<?php

namespace Datto\Backup\Transport;

use Datto\Log\DeviceLoggerInterface;

/**
 * Simple file transport, for each partition it just needs GUID, destination file and destination checksum file.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class LocalFileTransport extends BackupTransport
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var array */
    private $volumes;

    /**
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(DeviceLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function setup(array $imageLoopsOrFiles, array $checksumFiles, array $allVolumes)
    {
        $this->logger->debug("MBT0001 Using local file transport");
        $this->volumes = [];

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
        $this->logger->info("MBT0005 No cleanup necessary for local file transport.");
    }

    /**
     * @param string $volumeGuid
     * @param string $loopDev
     * @param string $checksumFileForLun
     */
    private function addVolume(string $volumeGuid, string $loopDev, string $checksumFileForLun)
    {
        $this->logger->debug("MBT0002 Adding lun for backup image $loopDev for volume $volumeGuid");
        $this->logger->debug("MBT0003 Adding lun for checksum file $checksumFileForLun for volume $volumeGuid");

        $this->volumes[] = [
            "guid" => $volumeGuid,
            "lun" => $loopDev,
            "lunChecksum" => $checksumFileForLun
        ];
    }
}
