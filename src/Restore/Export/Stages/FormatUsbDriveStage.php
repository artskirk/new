<?php

namespace Datto\Restore\Export\Stages;

use Datto\Filesystem\GptPartition;
use Datto\Filesystem\PartitionService;
use Datto\Restore\Export\Stages\AbstractStage;
use Datto\Restore\Export\Usb\UsbDrive;

/**
 * Freshly format the USB drive.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class FormatUsbDriveStage extends AbstractStage
{
    /** @var PartitionService */
    private $partitionService;

    public function __construct(PartitionService $partitionService)
    {
        $this->partitionService = $partitionService;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $this->partitionService->createGptBlockDevice($this->context->getUsbInformation()->getPath());

        $partition = new GptPartition(
            $this->context->getUsbInformation()->getPath(),
            UsbDrive::PARTITION_NUMBER,
            GptPartition::PARTITION_TYPE_MICROSOFT_BASIC
        );

        $this->partitionService->createSinglePartition($partition);
        $this->partitionService->formatNtfsPartition($partition);
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
    }
}
