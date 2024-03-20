<?php

namespace Datto\Core\Drives;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Block\LsBlk;
use Datto\Utility\Disk\Mvcli;
use Datto\Utility\Disk\Smartctl;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Throwable;

/**
 * A factory class that builds and returns Drive abstractions. Contains a minimum amount of logic
 * to disambiguate concrete drive types based on data read from `smartctl`.
 */
class DriveFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private Smartctl $smartctl;
    private Mvcli $mvcli;
    private LsBlk $lsBlk;

    public function __construct(
        Smartctl $smartctl,
        Mvcli $mvcli,
        LsBlk $lsBlk
    ) {
        $this->smartctl = $smartctl;
        $this->mvcli = $mvcli;
        $this->lsBlk = $lsBlk;
    }

    /**
     * Get a collection of all the physical drives on the device.
     *
     * @return AbstractDrive[]
     */
    public function getDrives(): array
    {
        $drives = [];
        foreach (array_unique($this->smartctl->scan()) as $device) {
            try {
                $this->addDrive($device, $drives);
            } catch (Throwable $exception) {
                $this->logger->warning('DFA0001 Could not build AbstractDrive', [
                    'device' => $device,
                    'exception' => $exception
                ]);
            }
        }
        return $drives;
    }

    /**
     * @param string $devPath
     * @return bool
     */
    private function isDriveRemovable(string $devPath): bool
    {
        try {
            $isRemovable = $this->lsBlk->getBlockDeviceByPath($devPath)->isUsb();
        } catch (RuntimeException $exception) {
            $isRemovable = false;
        }
        return $isRemovable;
    }

    /**
     * @param string $devPath The path to a device (/dev/sda, /dev/nvme0n1, etc...)
     * @param AbstractDrive[] $drives The array to add the drive(s) to
     */
    private function addDrive(string $devPath, array &$drives): void
    {
        if ($this->isDriveRemovable($devPath)) {
            $this->logger->info('DVF0004: The removable drive has been excluded from drive health', [
                'devPath' => $devPath
            ]);
            return;
        }
        $smartData = $this->smartctl->getAll($devPath);
        $this->logger->info('DVF0001: Get all the information from a drive.', ['smartData' => $smartData]);
        $driveType = $smartData['device']['type'] ?? 'unknown';
        switch ($driveType) {
            case 'sat':
                $modelName = $smartData['model_name'] ?? 'unknown';
                if ($modelName === 'DELLBOSS VD') {
                    $drives[] = new BossVirtualDrive($smartData, $this->mvcli->info('hba'));
                    $this->logger->info('DVF0002: Boss virtual drives', ['drives' => $drives]);
                    for ($i = 0; $i <= 1; $i++) {
                        $pdSmart = $this->mvcli->smart($i);
                        $pdInfo = $this->mvcli->info('pd', $i);
                        $this->logger->info('DVF0003: Get the mvcli smart attributes and pd info', [
                            'pdSmart' => $pdSmart,
                            'pdInfo' => $pdInfo
                        ]);
                        if ($pdInfo && $pdSmart) {
                            $drives[] = new BossPhysicalDrive($smartData, $pdInfo, $pdSmart);
                        }
                    }
                } else {
                    $drives[] = new SataDrive($smartData);
                }
                break;
            case 'nvme':
                $drives[] = new NvmeDrive($smartData);
                break;
            case 'scsi':
                $product = $smartData['scsi_product'] ?? 'unknown';
                if (strtolower($product) === 'virtual disk') {
                    $drives[] = new VirtualDrive($smartData);
                } else {
                    $drives[] = new SasDrive($smartData);
                }
                break;
            default:
                $this->logger->warning('DFA0002 Could not detect drive type', [
                    'devPath' => $devPath,
                    'smartData' => $smartData
                ]);
                break;
        }
    }
}
