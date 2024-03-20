<?php

namespace Datto\Restore\Export\Stages;

use Datto\Config\DeviceConfig;
use Datto\ImageExport\Export\Context;
use Datto\ImageExport\Export\ContextFactory;
use Datto\ImageExport\Export\ImageExporter;

/**
 * Base class for any shared functionality between stages which use the
 * disk-image-export library.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
abstract class AbstractImageExportStage extends AbstractStage
{
    const AZURE_DISK_SIZE_ALIGNMENT_BYTES = 1048576;  // 1 MiB

    private ContextFactory $contextFactory;
    protected ImageExporter $imageExporter;
    private DeviceConfig $deviceConfig;

    public function __construct(
        ContextFactory $contextFactory,
        ImageExporter $imageExporter,
        DeviceConfig $deviceConfig
    ) {
        $this->contextFactory = $contextFactory;
        $this->imageExporter = $imageExporter;
        $this->deviceConfig = $deviceConfig;
    }

    protected function createImageExportContext(): Context
    {
        $agent = $this->context->getAgent();
        $options = Context::OPT_REMOVE_BOOT_VMDK;

        if (!$agent->isSupportedOperatingSystem()) {
            $options |= Context::OPT_SKIP_HIR;
        }

        $imageExportContext = $this->contextFactory
            ->setAssetKey($agent->getKeyName())
            ->setAssetHostname($agent->getHostName())
            ->setBootType($this->context->getBootType())               // AUTO/UEFI/BIOS
            ->setFuseOverlayMount($this->context->getFuseOverlayMount())
            ->setImageType($this->context->getImageType())             // VHD/VHDX/VMDK
            ->setCloneMountPath($this->context->getCloneMountPoint())  // file path to mounted restore
            ->setOptions($options)
            ->getContext();

        if ($this->deviceConfig->isAzureDevice()) {
            // Set disk size boundary alignment, used if exporting a VHD for Azure consumption.
            $imageExportContext->setDiskSizeAlignToBytes(self::AZURE_DISK_SIZE_ALIGNMENT_BYTES);
        }

        return $imageExportContext;
    }
}
