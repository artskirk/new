<?php

namespace Datto\Asset\Agent;

use Datto\AppKernel;
use Datto\Block\LoopInfo;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Filesystem\SysFs;
use Datto\Block\LoopManager;
use Datto\Log\LoggerFactory;
use Datto\Utility\Block\Blockdev;
use Datto\Utility\Block\Dmsetup;
use Datto\Log\DeviceLoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Exception;
use Datto\Iscsi\IscsiTarget;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use Throwable;

/**
 * DMCrypt device management class. Used to mount encrypted systems via iSCSI.
 *
 * @author Dan Fuhry
 * @date June 12, 2013
 * @copyright 2013 Datto Inc.
 */
class DmCryptManager implements LoggerAwareInterface
{
    const DM_CRYPT_SIGNIFIER = '-crypt-';

    const DEVICE_MAPPER_PATH = '/dev/mapper';

    /**
     * Maximum number of times to attempt to detach dmcrypt devices
     */
    const MAX_DETACH_ATTEMPTS = 3;

    /** @var Filesystem */
    private $filesystem;

    /** @var EncryptionService */
    private $encryption;

    /** @var LoopManager */
    private $loopManager;

    /** @var SysFs */
    private $sysfs;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Blockdev */
    private $blockdevUtility;

    /** @var Dmsetup */
    private $dmSetupUtility;

    public function __construct(
        Filesystem $filesystem = null,
        EncryptionService $encryption = null,
        LoopManager $loop = null,
        SysFs $sysfs = null,
        DeviceLoggerInterface $logger = null,
        Blockdev $blockdevUtility = null,
        Dmsetup $dmSetupUtility = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->encryption = $encryption
            ?: AppKernel::getBootedInstance()->getContainer()->get(EncryptionService::class);
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->sysfs = $sysfs ?: new SysFs($this->filesystem);
        $this->loopManager = $loop ?: new LoopManager(
            $this->logger,
            $this->filesystem
        );
        $this->blockdevUtility = $blockdevUtility ?: new Blockdev(new ProcessFactory());
        $this->dmSetupUtility = $dmSetupUtility ?? new Dmsetup(new ProcessFactory());
    }

    /**
     * Attach an image to a Device Mapper device using dm-crypt.
     *
     * @param string $path Path to image file or loop device
     * @param string $key 256 bit (64 hex character) AES encryption key
     * @param int $offset into image. Defaults to zero. Must be bytes, evenly divisible by 512.
     * @return string Path to Device Mapper block device
     *
     */
    public function attach(string $path, string $key, int $offset = 0): string
    {
        $blockdev = null;
        if ($this->filesystem->isFile($path)) {
            $loopInfo = $this->loopManager->create($path, 0, $offset);
            $blockdev = $loopInfo->getPath();
        } elseif ($this->filesystem->isBlockDevice($path)) {
            $blockdev = $path;
        } else {
            $this->logAndThrowCritical(
                'ENC0001',
                'Path must be a regular file or a block device, provided path is neither.',
                ['path' => $path]
            );
        }

        if ($offset % 512) {
            $this->logAndThrowCritical('ENC0002', 'Offset must be a multiple of 512');
        }

        $ivOffset = $offset / 512;

        $key = preg_match('/^([a-f0-9]{64}){1,2}$/', $key) ? $key : bin2hex($key);
        if (strlen($key) !== 64 && strlen($key) !== 128) {
            $this->logAndThrowCritical('ENC0003', 'Encryption key must be 64 or 128 hex characters');
        }

        try {
            $sizeInSectors = $this->blockdevUtility->getSizeInSectors($blockdev);
        } catch (Throwable $ex) {
            $this->logAndThrowCritical(
                'ENC0004',
                'Failed to get size of block device',
                ['blockDevice' => $blockdev, 'error' => $ex->getMessage()]
            );
        }

        $tableFile = $this->filesystem->tempName(
            $this->filesystem->getSysTmpDir(),
            'dmcrypt'
        );
        $this->filesystem->filePutContents(
            $tableFile,
            "0 $sizeInSectors crypt aes-xts-plain64 $key $ivOffset $blockdev 0\n"
        );

        $rand = substr(sha1(microtime() . mt_rand()), 0, 8);
        $dmBasename = preg_replace('/\.d[ae]tto$/', '', basename($path)) . static::DM_CRYPT_SIGNIFIER . $rand;

        try {
            $this->dmSetupUtility->create($dmBasename, $tableFile, false);
        } catch (Throwable $ex) {
            $this->logAndThrowCritical(
                'ENC0005',
                'Failed to create Device Mapper device',
                ['error' => $ex->getMessage()]
            );
        } finally {
            $this->filesystem->unlink($tableFile);
        }

        return "/dev/mapper/$dmBasename";
    }

    /**
     * Detach an attached dm-crypt device. Please don't call this on DM devices that were not created using this class.
     *
     * @param string $path
     *   Image file, DM device, or intermediate loop device
     *
     * @return bool
     *   TRUE if the dm-crypt device was successfully removed. Otherwise, it
     *   will thrown an exception.
     */
    public function detach(string $path): bool
    {
        if (!$this->filesystem->exists($path)) {
            $this->logAndThrowCritical(
                'ENC0006',
                'Specified path no longer exists',
                ['path' => $path]
            );
        }

        $dmDevices = $this->sysfs->getDmDevices();

        if ($this->filesystem->isFile($path)) {
            // find out if any Device Mapper devices depend on this loop device
            foreach ($dmDevices as $dmDevice) {
                $slaveLoops = $this->sysfs->getSlaves($dmDevice['path']);
                foreach ($slaveLoops as $loop) {
                    $backingFile = $loop->getBackingFilePath();
                    if (realpath($backingFile) === realpath($path)) {
                        $this->detachDmDevice($path, $dmDevice, $slaveLoops);
                    }
                }
            }
        } elseif ($this->filesystem->isBlockDevice($path)) {
            // it's a block device, figure out if it's already a DM device and remove
            $dmDeviceInfo = null;
            foreach ($dmDevices as $dmDevice) {
                if ($this->filesystem->realpath($path) === $dmDevice['path']) {
                    $dmDeviceInfo = $dmDevice;
                    break;
                }
            }

            if ($dmDeviceInfo) {
                $this->detachDmDevice($path, $dmDeviceInfo);
            } elseif (preg_match('/^loop[0-9]+$/', basename($path))) {
                // it's a loop block device, find associated DM devices and remove
                foreach ($dmDevices as $dmDev) {
                    $slaveLoops = $this->sysfs->getSlaves($dmDev['path']);
                    // get just array of loop paths
                    $loopPaths = array_map(function (LoopInfo $loopInfo) {
                        return basename($loopInfo->getPath());
                    }, $slaveLoops);

                    if (in_array(basename($path), $loopPaths)) {
                        $this->detachDmDevice(
                            $path,
                            $dmDev,
                            $slaveLoops
                        );
                    }
                }
            } else {
                $this->logAndThrowCritical(
                    'ENC0007',
                    'Unsupported block device type, please pass the image path, loop device or DM device node',
                    ['path' => $path]
                );
            }
        }

        return true;
    }

    /**
     * Attempts to detach a device, if not successful, flush the buffers
     * @param $dmDevice
     */
    private function attemptDetachDevice(string $dmDevice): void
    {
        $this->logger->info('ENC0013 Trying to detach dm-device', ['dmDevice' => $dmDevice]);
        $success = false;
        for ($i = 0; $i <= self::MAX_DETACH_ATTEMPTS && !$success; $i++) {
            try {
                $this->detach($dmDevice);
                $this->logger->info("ENC0015 Dm-device detached successfully");
                $success = true;
                break;
            } catch (\Exception $exception) {
                $this->logger->error('ENC0014 Error detaching dm-device', ['error' => $exception->getMessage()]);
                sleep(5);
            }
        }

        if (!$success) {
            $this->logger->error("ENC0016 Error detaching dm-device, attempts exhausted. Flushing dm-device buffers...");
            $this->blockdevUtility->flushBuffers($dmDevice);
        }
    }

    /***
     * Find dm-crypt devices attached to the specified image file.
     *
     * @param string $image
     * @param bool $returnFullPath Whether return the full paths to the devices or just the names.
     * @return array List of device-mapper device names attached to this image.
     */
    public function getDMCryptDevicesForFile(string $image, bool $returnFullPath = false): array
    {
        $allDmDevices = $this->sysfs->getDmDevices();
        $dmDevices = array();

        foreach ($allDmDevices as $dmDevice) {
            $slaveLoops = $this->sysfs->getSlaves($dmDevice['path']);
            foreach ($slaveLoops as $loopInfo) {
                if ($image === trim($loopInfo->getBackingFilePath())) {
                    if ($returnFullPath) {
                        $dmDevices[] = $dmDevice['path'];
                    } else {
                        $dmDevices[] = $dmDevice['name'];
                    }
                }
            }
        }

        return $dmDevices;
    }

    /**
     * Given the path to a .datto image, auto-decrypt if necessary.
     * @deprecated Use attach / detach methods or DattoImage instead.
     * @param string $image
     * @param string $key
     * @return string The path you should use to access it
     */
    public function makeTransparent(string $image, string $key, bool $isRescueAgentSnapshot = false): string
    {
        if (!preg_match('/\.datto$/', $image)) {
            $this->logAndThrowCritical(
                'ENC0008',
                'Image filename does not end in .datto (don\'t pass detto images to this routine)',
                ['imagePath' => $image]
            );
        }

        if (!$isRescueAgentSnapshot && $this->filesystem->exists($image) && !$this->isBrokenSymlink($image)) {
            return $image;
        }

        if (@$this->filesystem->lstat($image)) {
            $this->filesystem->unlink($image);
        }

        $detto = preg_replace('/\.datto$/', '.detto', $image);

        if (!$this->filesystem->exists($detto)) {
            $this->logAndThrowCritical(
                'ENC0009',
                'The image specified does not exist in an encrypted form.'
            );
        }

        $loopDev = $this->attach($detto, $key);

        $this->filesystem->chmod($loopDev, 0666);

        if ($this->filesystem->symlink($loopDev, $image)) {
            return $image;
        } else {
            return $loopDev;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setLogger(LoggerInterface $logger): void
    {
        if (!($logger instanceof DeviceLoggerInterface)) {
            throw new InvalidTypeException('setLogger expected type ' . DeviceLoggerInterface::class . ', received type ' . get_class($logger));
        }
        $this->logger = $logger;
        $this->loopManager->setLogger($logger);
    }

    /**
     * Remove DM device and dependent loops.
     * It also removes the related iSCSI targets.
     *
     * @param string $path path to the backing file
     * @param array $dmDevice
     * @param LoopInfo[]|null $slaveLoops will be looked up internally if null
     */
    private function detachDmDevice(string $path, array $dmDevice, array $slaveLoops = null): void
    {
        if ($slaveLoops === null) {
            $slaveLoops = $this->sysfs->getSlaves($dmDevice['path']);
        }

        if ($this->filesystem->exists($path)) {
            // TODO: NEWBACKUP, remove iSCSI target detaching logic from here.
            $iScsiTarget = new IscsiTarget();
            $targets = $iScsiTarget->getTargetsByPath($path);
            foreach ($targets as $target) {
                $iScsiTarget->deleteTarget($target);
            }
        }

        $this->blockdevUtility->flushBuffers($path);

        // delete any non-slave loops pointing at DM device
        $loops = $this->loopManager->getLoops();
        foreach ($loops as $loopInfo) {
            if ($loopInfo->getBackingFilePath() === $dmDevice['path']) {
                $this->loopManager->destroy($loopInfo);
            }
        }

        $this->destroyDMPartitions($dmDevice['name']);

        try {
            $this->dmSetupUtility->destroy($dmDevice['name']);
        } catch (Exception $ex) {
            $this->logAndThrowCritical(
                'ENC0011',
                'Failed to remove DM Device',
                ['dmDevice' => $dmDevice['name'], 'error' => $ex->getMessage()]
            );
        }

        // get rid of slave loops
        foreach ($slaveLoops as $loopInfo) {
            if ($this->sysfs->loopExists($loopInfo->getPath())) {
                $this->loopManager->destroy($loopInfo);
            }
        }
    }

    /**
     * Test if something is a broken symlink
     * @param string $path
     * @return bool
     * @access private
     */
    private function isBrokenSymlink(string $path): bool
    {
        // FIXME this should be a better check (it should handle relative symlinks)
        if ($this->filesystem->isLink($path) &&
            !$this->filesystem->exists($this->filesystem->readlink($path))) {
            return true;
        }

        return false;
    }

    /**
     * Formats the message for logging.
     *
     * @param string $code
     * @param string $message
     *
     * @return string formatted log message to be passed to the logger.
     */
    private function prepareLogMessage(string $code, string $message): string
    {
        return sprintf('%s %s', $code, $message);
    }

    /**
     * Logs a critical event and throws exception.
     *
     * Makes sure any critical event is logged and throws exception to abort exection
     * and let caller decide how to deal with that.
     *
     * @param string $code
     * @param string $message
     * @param array $context
     */
    private function logAndThrowCritical(string $code, string $message, array $context = []): void
    {
        $logMessage = $this->prepareLogMessage($code, $message);
        $this->logger->critical($logMessage, $context); // nosemgrep: utils.security.semgrep.log-context-in-message, utils.security.semgrep.log-no-log-code

        throw new Exception($message);
    }

    /**
     * Logs a message for informational purposes.
     *
     * This must be public because it's called in shutdown function callback.
     *
     * @param string $code
     * @param string $message
     *
     * @return void
     */
    public function logInfo(string $code, string $message)
    {
        $logMessage = $this->prepareLogMessage($code, $message);
        $this->logger->info($logMessage); // nosemgrep
    }

    /**
     * Remove partition devices on a Device Mapper device placed there by partprobe(8).
     *
     * @param string $dmDevice device or path
     * @access private
     */
    public function destroyDMPartitions(string $dmDevice): void
    {
        $dmName = basename($dmDevice);
        $dmName = preg_quote($dmName, ';');

        $dmDevices = $this->sysfs->getDmDevices();

        foreach ($dmDevices as $device) {
            $name = $device['name'];
            // match either '/dev/mapper/foop1' or '/dev/mapper/foo1'
            $looksLikeDmPartition = strlen($name) > 47 && preg_match(';^' . $dmName . 'p?[0-9]+$;', $name);

            if ($looksLikeDmPartition === false) {
                continue;
            }

            try {
                $this->dmSetupUtility->destroy($name);
            } catch (Exception $ex) {
                $this->logAndThrowCritical(
                    'ENC0012',
                    'Failed to remove Device Mapper partition device',
                    ['partitionDevice' => $name, 'error' => $ex->getMessage()]
                );
            }
        }
    }
}
