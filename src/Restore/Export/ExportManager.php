<?php

namespace Datto\Restore\Export;

use Datto\Agentless\OffsetMapper;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\AssetException;
use Datto\Asset\AssetService;
use Datto\Core\Network\DeviceAddress;
use Datto\ImageExport\ImageType;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Utility\Screen;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\Security\SecretString;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Class to manage image exports on the device
 *
 * @author Christopher Bitler <cbitler@datto.com>
 */
class ExportManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private RestoreService $restoreService;
    private Screen $screen;
    private Filesystem $filesystem;
    private EncryptionService $encryptionService;
    private TempAccessService $tempAccessService;
    private DeviceAddress $deviceAddress;
    private ?Collector $collector;
    private AssetService $assetService;
    private AgentService $agentService;

    public function __construct(
        RestoreService $restoreService,
        Filesystem $filesystem,
        EncryptionService $encryptionService,
        TempAccessService $tempAccessService,
        AssetService $assetService,
        AgentService $agentService,
        DeviceAddress $deviceAddress,
        Screen $screen,
        Collector $collector
    ) {
        $this->restoreService = $restoreService;
        $this->filesystem = $filesystem;
        $this->encryptionService = $encryptionService;
        $this->tempAccessService = $tempAccessService;
        $this->deviceAddress = $deviceAddress;
        $this->screen = $screen;
        $this->collector = $collector;
        $this->assetService = $assetService;
        $this->agentService = $agentService;
    }

    /**
     * Create a network share export using the specified image type, agent, and snapshot
     * @param string $agentName
     * @param string $snapshotEpoch
     * @param string $imageType
     * @param SecretString|null $passphrase
     * @param string|null $bootType
     * @return array
     */
    public function createShareExport(
        string $agentName,
        string $snapshotEpoch,
        string $imageType,
        SecretString $passphrase = null,
        string $bootType = null
    ): array {
        $asset = $this->assetService->get($agentName);
        $type = ImageType::get($imageType);

        $this->collector->increment(Metrics::RESTORE_STARTED, [
            'type' => Metrics::RESTORE_TYPE_IMAGE_EXPORT_NETWORK,
            'is_replicated' => $asset->getOriginDevice()->isReplicated(),
        ]);

        $this->collector->increment(Metrics::RESTORE_IMAGE_EXPORT_NETWORK_STARTED, [
            'imageType' => $type->value(),
            'is_replicated' => $asset->getOriginDevice()->isReplicated(),
        ]);

        if ($this->encryptionService->isEncrypted($agentName) &&
            !$this->tempAccessService->isCryptTempAccessEnabled($agentName)
        ) {
            $this->encryptionService->decryptAgentKey($agentName, $passphrase);
        }

        // NOTE: --no-interaction is required to prevent the command for prompting for encrypted agent password
        $command = [
            'snapctl',
            'export:network:create',
            '--no-interaction',
            $agentName,
            $snapshotEpoch,
            $type->value(),
        ];

        if ($bootType) {
            $command[] = '--boot-type';
            $command[] = $bootType;
        }

        $screenName = $this->getScreenName($agentName, $snapshotEpoch, $type);
        if ($this->screen->isScreenRunning($screenName)) {
            $running = true;
        } else {
            $running = $this->screen->runInBackground($command, $screenName);
        }
        return array(
            'running' => $running
        );
    }

    /**
     * Get the status of an export
     *
     * @param string $agentName
     * @param string $snapshotEpoch
     * @param string $imageType
     * @return array
     */
    public function getShareExportStatus(
        string $agentName,
        string $snapshotEpoch,
        string $imageType
    ): array {
        $type = ImageType::get($imageType);

        // check to see if the restore has been created
        $restore = $this->restoreService->find($agentName, $snapshotEpoch, RestoreType::EXPORT);
        $screenName = $this->getScreenName($agentName, $snapshotEpoch, $type);
        return array(
            'running' => $this->screen->isScreenRunning($screenName),
            'restore' => $restore !== null ? $restore->getUiData() : null
        );
    }

    /**
     * This checks to see if there is an active export with a specific name and gets the share
     * information if there is one.
     *
     * @param string $exportName The name of the export
     * @return array Array with the image type, boot type, share path, nfs path, is completed, is failed
     *  and isSecureFileExportAndRestore
     */
    public function getExportShare(string $exportName): array
    {
        $restores = $this->restoreService->getAll();
        foreach ($restores as $restore) {
            $exportString = $restore->getAssetKey() . $restore->getPoint() . $restore->getSuffix();
            if ($exportString === $exportName) {
                $options = $restore->getOptions();
                $imageType = $options['image-type'];
                $bootType = $options['boot-type'] ?? '';
                $networkExport = $options['network-export'] ?? true;
                $shareName = $options['share-name'];
                $nfsPathOption = $options['nfs-path'];
                $isCompleted = $options['complete'] ?? true;
                $isFailed = $options['failed'] ?? false;
                $ipAddresses = $this->deviceAddress->getActiveIpAddresses();

                $sharePaths = array_map(fn($ip) => sprintf(
                    '\\\\%s\\%s',
                    $ip,
                    $shareName
                ), $ipAddresses);

                $isSecureFileRestoreAndExport = $this->isSecureFileRestoreAndExport($restore);
                if ($isSecureFileRestoreAndExport) {
                    $nfsPaths = [];
                } else {
                    $nfsPaths = array_map(fn($ip) => sprintf(
                        '%s:%s',
                        $ip,
                        $nfsPathOption
                    ), $ipAddresses);
                }

                return [
                    'imageType' => $imageType,
                    'bootType' => $bootType,
                    'networkExport' => $networkExport,
                    'sharePaths' => $sharePaths,
                    'nfsPaths' => $nfsPaths,
                    'isCompleted' => $isCompleted,
                    'isFailed' => $isFailed,
                    'isSecureFileRestoreAndExport' => $isSecureFileRestoreAndExport
                ];
            }
        }

        return [
            'error' => [
                'message' => "No such export"
            ]
        ];
    }

    /**
     * Get if Secure File Export & Restore is enabled
     *
     * @param Restore $restore
     * @return bool
     */
    private function isSecureFileRestoreAndExport(Restore $restore): bool
    {
        try {
            $asset = $restore->getAssetObject();
        } catch (AssetException $exception) {
            $this->logger->warning(
                'EMR0001 Error finding asset to get share auth for export',
                ['exception' => $exception, 'asset' => $restore->getAssetKey()]
            );
            // Should never really happen, but fallback on providing the export info even so.
            return false;
        }

        // Only agents can create an export restore
        if (!$asset instanceof Agent) {
            return false;
        }

        // Check if Secure File Export & Restore is enabled by checking if Agent has Share Auth User.
        if (empty($asset->getShareAuth()->getUser())) {
            return false;
        }
        return true;
    }

    /**
     * Get a list of USB drives plugged into the device for usb export
     *
     * @return array List of USB drives in the format of the path to their device
     */
    private function getUsbDriveList(): array
    {
        $drives = [];
        $blockDevices = $this->filesystem->glob("/sys/block/*") ?? [];
        foreach ($blockDevices as $blockDevice) {
            $blockDevice = $this->filesystem->basename($blockDevice);
            if (!preg_match('/^sd[a-z][a-z]?$/', $blockDevice)) {
                continue;
            }

            $realPath = "/sys/block/$blockDevice/" . $this->filesystem->readlink("/sys/block/$blockDevice/device");
            $realPath = $this->filesystem->realpath($realPath);

            // Is this device USB?
            if (strpos($realPath, "usb") !== false) {
                $drives[] = "/dev/$blockDevice";
            }
        }

        return $drives;
    }

    /**
     * Get the size of a usb drive using fdisk
     *
     * @param string $driveDevice The device location (/dev/[device]) of the drive
     * @return int The size or 0 if the device was not found
     */
    private function getUsbDriveSize(string $driveDevice): int
    {
        //Note: The sys/block/[device]/size filesystem node returns the size in sectors.
        $device = str_replace("/dev/", "", $driveDevice);
        $blockSizeLoc = "/sys/block/" . $device . "/size";
        if ($this->filesystem->exists($blockSizeLoc)) {
            //TODO: This assumes a sector size of 512 bytes per sector. This is true for now but could change
            $size = intval($this->filesystem->fileGetContents($blockSizeLoc)) * OffsetMapper::SECTOR_SIZE;
            return $size;
        }
        return 0;
    }

    /**
     * Get the location and size of the currently plugged in USB disk
     *
     * @return array The disk and size information
     */
    public function getUsbInformation(): array
    {
        $drives = $this->getUsbDriveList();
        $numDrives = count($drives);
        $disk = null;
        if ($numDrives == 1) {
            $disk = $drives[0];
            $size = $this->getUsbDriveSize($disk);
        } elseif ($numDrives == 0) {
            throw new Exception("No USB drive detected");
        } else {
            throw new Exception("Multiple USB drives detected");
        }

        return array(
            'disk' => $disk,
            'size' => $size
        );
    }

    /**
     * @param string $agentName
     * @param string $snapshotEpoch
     * @param ImageType $type
     * @return string
     */
    private function getScreenName(string $agentName, string $snapshotEpoch, ImageType $type): string
    {
        return sprintf(
            '%s-%s-%s',
            $agentName,
            $snapshotEpoch,
            $type->value()
        );
    }
}
