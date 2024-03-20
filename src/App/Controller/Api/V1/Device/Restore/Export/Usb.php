<?php

namespace Datto\App\Controller\Api\V1\Device\Restore\Export;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentRepository;
use Datto\ImageExport\BootType;
use Datto\ImageExport\ImageType;
use Datto\Restore\Export\ExportManager;
use Datto\Restore\Export\Usb\UsbExportService;
use Datto\Restore\Export\Usb\UsbExportProgress;
use Datto\Restore\Export\Usb\UsbExportProgressService;
use Datto\Utility\ByteUnit;

/**
 * API endpoint for actions related to USB Image Exports
 *
 * @author Christopher Bitler <cbitler@datto.com>
 */
class Usb
{
    /** @var ExportManager */
    private $exportManager;

    /** @var AgentRepository */
    private $agentRepository;

    /** @var UsbExportService */
    private $usbExportService;

    /** @var UsbExportProgressService */
    private $usbExportProgressService;

    public function __construct(
        ExportManager $exportManager,
        AgentRepository $agentRepository,
        UsbExportService $usbExportService,
        UsbExportProgressService $usbExportProgressService
    ) {
        $this->exportManager = $exportManager;
        $this->agentRepository = $agentRepository;
        $this->usbExportService = $usbExportService;
        $this->usbExportProgressService = $usbExportProgressService;
    }

    /**
     * Get the information on the current USB drive and agent volumes to
     * display on the USB Image Export page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_IMAGE_EXPORT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_IMAGE_EXPORT_WRITE")
     *
     * @param string $agentKeyName Name of the agent that is being exported
     * @return array Array containing two subarrays, usb and volumes, with
     *   usb information and agent volume information respectively.
     */
    public function getUsbExportInformation(string $agentKeyName): array
    {
        $driveData = $this->exportManager->getUsbInformation();

        /** @var Agent $agent */
        $agent = $this->agentRepository->get($agentKeyName);
        $volumesData = $agent->getVolumes();
        $volumes = array();

        foreach ($volumesData as $volume) {
            $spaceUsedBytes = $volume->getSpaceTotal() - $volume->getSpaceFree();
            $spaceUsedInMb = ceil(ByteUnit::BYTE()->toMiB($volume->getSpaceTotal() - $volume->getSpaceFree()));
            $spaceUsed = $spaceUsedInMb <= ByteUnit::GIB()->toMiB(1)
                ? ($spaceUsedInMb . " MB")
                : (ceil(ByteUnit::MIB()->toGiB($spaceUsedInMb)) . " GB");
            $volumes[] = array(
                'mountpoint' => $volume->getMountpoint(),
                'label' => $volume->getLabel(),
                'guid' => $volume->getGuid(),
                'spaceTotal' => $volume->getSpaceTotal(),
                'spaceFree' => $volume->getSpaceFree(),
                'spaceUsed' => $spaceUsed,
                'spaceUsedBytes' => $spaceUsedBytes
            );
        }

        return array(
            'usb' => $driveData,
            'volumes' => $volumes
        );
    }

    /**
     * Start an image export.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_IMAGE_EXPORT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_IMAGE_EXPORT_WRITE")
     *
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentKeyName" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *     "snapshot" = @Symfony\Component\Validator\Constraints\Type("integer"),
     *     "format" = @Symfony\Component\Validator\Constraints\Choice(choices = {"vhd", "vhdx", "vmdk", "vmdklinked"})
     * })
     *
     * @param string $agentKeyName
     * @param int $snapshot
     * @param string $format
     * @param string|null $bootType
     * @return bool
     */
    public function start(string $agentKeyName, int $snapshot, string $format, string $bootType = null): bool
    {
        $imageType = ImageType::get($format);
        $bootMode = null;
        if ($bootType) {
            $bootMode = BootType::get(strtolower($bootType));
        }
        return $this->usbExportService->startAsync($agentKeyName, $snapshot, $imageType, $bootMode);
    }

    /**
     * Cancel an active export.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_IMAGE_EXPORT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_IMAGE_EXPORT_WRITE")
     *
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "agentKeyName" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *     "snapshot" = @Symfony\Component\Validator\Constraints\Type("integer"),
     *     "format" = @Symfony\Component\Validator\Constraints\Choice(choices = {"vhd", "vhdx", "vmdk", "vmdklinked"})
     * })
     *
     * @param string $agentKeyName
     * @param int $snapshot
     * @param string $format
     * @return bool
     */
    public function cancel(string $agentKeyName, int $snapshot, string $format)
    {
        $imageType = ImageType::get($format);
        $this->usbExportService->cancel($agentKeyName, $snapshot, $imageType);
        return true;
    }

    /**
     * Get the current status of the active export.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_IMAGE_EXPORT")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_IMAGE_EXPORT_READ")
     *
     * @return array
     */
    public function status(): array
    {
        if ($this->usbExportService->isExportAborted()) {
            return ["state" => "aborted"];
        }

        $progress = $this->usbExportProgressService->getProgress();

        switch ($progress->getCurrentState()) {
            case UsbExportProgress::STATE_STARTING:
                return ["state" => "starting"];

            case UsbExportProgress::STATE_TRANSFER:
                return [
                    "state" => "transfer",
                    "totalTransferred" => $progress->getCurrentBytes(),
                    "sourceSize" => $progress->getTotalBytes(),
                    "percent" => $progress->getPercent(),
                    "speed" => $progress->getTransferRate(),
                    "fileNum" => $progress->getCurrentFile(),
                    "fileCount" => $progress->getTotalFiles()
                ];

            case UsbExportProgress::STATE_FINISHING:
                return ["state" => "finishing"];

            case UsbExportProgress::STATE_SUCCESS:
                return ["state" => "complete"];

            case UsbExportProgress::STATE_CANCELING:
                return ["state" => "canceling"];

            case UsbExportProgress::STATE_FAILED:
                return ["state" => "error", "message" => $progress->getMessage()];
        }

        return [];
    }
}
