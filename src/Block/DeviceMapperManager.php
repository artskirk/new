<?php

namespace Datto\Block;

use Datto\Filesystem\SysFs;
use Datto\Common\Utility\Filesystem;
use Datto\System\MountManager;
use Datto\Util\RetryHandler;
use Datto\Utility\Block\Blockdev;
use Datto\Utility\Block\Dmsetup;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Handles creating and releasing device mappers
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class DeviceMapperManager
{
    use PartitionableBlockDeviceTrait;

    const DM_CRYPT_SIGNIFIER = '-crypt-';

    /**
     * Device mappers should be accessed through /dev/mapper/[DM_NAME] where possible,
     * as the association of /dev/dm-[NUMBER] to the block device could be updated by udev at any time.
     */
    const DEVICE_MAPPER_PATH = '/dev/mapper';

    /** @var int Number of times to retry destroying a device mapper */
    const DESTROY_RETRY_LIMIT = 3;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Filesystem */
    private $filesystem;

    /** @var Blockdev */
    private $blockdev;

    /** @var Dmsetup */
    private $dmsetup;

    /** @var SysFs */
    private $sysFs;

    /** @var MountManager */
    private $mountManager;

    /** @var RetryHandler */
    private $retryHandler;

    /**
     * @param DeviceLoggerInterface $logger
     * @param Filesystem $filesystem
     * @param Blockdev $blockdev
     * @param Dmsetup $dmsetup
     * @param SysFs $sysFs
     * @param MountManager $mountManager
     * @param RetryHandler $retryHandler
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        Blockdev $blockdev,
        Dmsetup $dmsetup,
        SysFs $sysFs,
        MountManager $mountManager,
        RetryHandler $retryHandler
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->blockdev = $blockdev;
        $this->dmsetup = $dmsetup;
        $this->sysFs = $sysFs;
        $this->retryHandler = $retryHandler;
        $this->mountManager = $mountManager;
    }

    /**
     * Create a device mapper
     *
     * @param string $backingFilePath Path to the backing file
     * @param string $encryptionKey Key used to encrypt and decrypt the data
     * @param string $namePrefix Prefix to add to the device mapper name
     * @param bool $readonly
     * @return string Path to the device mapper
     */
    public function create(string $backingFilePath, string $encryptionKey, string $namePrefix, bool $readonly): string
    {
        $this->assertBackingFileIsBlockDevice($backingFilePath);

        // This was carried over from DmCryptManager
        // todo: revisit this after the adoption of DattoImage and ensure that the encryption key
        // todo: is either consistently binary or a hex string throughout the codebase
        $encryptionKey = preg_match('/^([a-f0-9]{64}){1,2}$/', $encryptionKey) ? $encryptionKey : bin2hex($encryptionKey);
        $this->assertKeyIsValidLength($encryptionKey);

        $deviceMapperName = $this->getDeviceMapperName($namePrefix);
        $tableFile = $this->createTableFile($backingFilePath, $encryptionKey);

        $this->createDeviceMapper($deviceMapperName, $tableFile, $readonly);

        $this->removeTableFile($tableFile);

        return $this->getFullPath($deviceMapperName);
    }

    /**
     * Destroy a device mapper
     *
     * @param string $deviceMapperPath Path to the device mapper
     */
    public function destroy(string $deviceMapperPath)
    {
        $this->assertDeviceMapperExists($deviceMapperPath);
        $this->assertBlockDeviceIsDeviceMapper($deviceMapperPath);

        $this->destroyDeviceMapper($deviceMapperPath);
    }

    /**
     * Get the first device mapper dependency.
     * For our use, we are only expect to have one dependency, the underlying loop.
     *
     * @param string $deviceMapperPath
     * @return string
     */
    public function getBackingFile(string $deviceMapperPath): string
    {
        $realDeviceMapperPath = realpath($deviceMapperPath);
        $loops = $this->sysFs->getSlaves($realDeviceMapperPath, $loopsOnly = true);
        $loopPath = count($loops) > 0 ? $loops[0]->getPath() : '';
        return $loopPath;
    }

    /**
     * Verify that the backing file is a block device
     *
     * @param string $backingFilePath Path to the backing file
     */
    private function assertBackingFileIsBlockDevice(string $backingFilePath)
    {
        if (!$this->filesystem->isBlockDevice($backingFilePath)) {
            $message = 'Backing file must be a block device';
            $this->logger->critical("DMM0001 $message", ['backingFilePath' => $backingFilePath]);
            throw new Exception("$message: $backingFilePath");
        }
    }

    /**
     * Verify that the encryption key is a valid length
     *
     * @param string $encryptionKey
     */
    private function assertKeyIsValidLength(string $encryptionKey)
    {
        if (strlen($encryptionKey) !== 64 &&
            strlen($encryptionKey) !== 128) {
            $message = 'Encryption key must be 64 or 128 hex characters';
            $this->logger->critical("DMM0002 $message", ['keylength' => strlen($encryptionKey)]);
            throw new Exception($message);
        }
    }

    /**
     * Verify that the device mapper exists
     *
     * @param string $deviceMapperPath
     */
    private function assertDeviceMapperExists(string $deviceMapperPath)
    {
        if (!$this->filesystem->exists($deviceMapperPath)) {
            $message = 'Specified path no longer exists';
            $this->logger->error("DMM0005 $message", ['deviceMapperPath' => $deviceMapperPath]);
            throw new Exception("$message: $deviceMapperPath");
        }
    }

    /**
     * Verify that the given block device is a device mapper
     *
     * @param string $deviceMapperPath
     */
    private function assertBlockDeviceIsDeviceMapper(string $deviceMapperPath)
    {
        if (!$this->isDeviceMapper($deviceMapperPath)) {
            $message = 'Specified path is not a device mapper';
            $this->logger->error("DMM0006 $message", ['deviceMapperPath' => $deviceMapperPath]);
            throw new Exception("$message: $deviceMapperPath");
        }
    }

    /**
     * Create the dmcrypt table file
     *
     * @param string $backingFilePath Path to the backing file
     * @param string $encryptionKey Key used to encrypt and decrypt the data
     * @return string Path to the dmcrypt table file
     */
    private function createTableFile(string $backingFilePath, string $encryptionKey): string
    {
        $ivOffset = 0;
        $sizeInSectors = $this->getSizeOfBlockDeviceInSectors($backingFilePath);

        $tableFile = $this->filesystem->tempName(
            $this->filesystem->getSysTmpDir(),
            'dmcrypt'
        );

        $this->filesystem->filePutContents(
            $tableFile,
            "0 $sizeInSectors crypt aes-xts-plain64 $encryptionKey $ivOffset $backingFilePath 0\n"
        );

        return $tableFile;
    }

    /**
     * Remove the dmcrypt table file
     *
     * @param string $tableFile Path to the dmcrypt table file
     */
    private function removeTableFile(string $tableFile)
    {
        $this->filesystem->unlink($tableFile);
    }

    /**
     * Get the size of the backing file in sectors
     *
     * @param string $backingFilePath Path to the backing file
     * @return int Size in sectors
     */
    private function getSizeOfBlockDeviceInSectors(string $backingFilePath): int
    {
        try {
            $sizeInSectors = $this->blockdev->getSizeInSectors($backingFilePath);
        } catch (Throwable $throwable) {
            $message = 'Failed to get size of block device';
            $this->logger->critical("DMM0003 $message", ['backingFilePath' => $backingFilePath, 'error' => $throwable->getMessage()]);
            throw new Exception("$message: $backingFilePath", $throwable->getCode(), $throwable);
        }
        return $sizeInSectors;
    }

    /**
     * Get a generated device mapper name
     *
     * @param string $namePrefix Prefix to add to the device mapper name
     * @return string Device mapper name
     */
    private function getDeviceMapperName(string $namePrefix)
    {
        $randomSuffix = substr(sha1(microtime() . mt_rand()), 0, 8);
        $name = $namePrefix . static::DM_CRYPT_SIGNIFIER . $randomSuffix;
        return $name;
    }

    /**
     * Create the device mapper
     *
     * @param string $deviceMapperName Name of the device mapper
     * @param string $tableFile Path to the table file
     * @param bool $readonly
     */
    private function createDeviceMapper(string $deviceMapperName, string $tableFile, bool $readonly)
    {
        try {
            $this->dmsetup->create($deviceMapperName, $tableFile, $readonly);
            $deviceMapperPath = $this->getFullPath($deviceMapperName);
            $this->logger->info(
                'DMM0008 Created device mapper',
                ['deviceMapperPath' => $deviceMapperPath, 'realpath' => realpath($deviceMapperPath)]
            );
        } catch (Throwable $throwable) {
            $message = 'Failed to create device mapper';
            $this->logger->critical("DMM0004 $message", ['deviceMapperName' => $deviceMapperName, 'error' => $throwable->getMessage()]);
            throw new Exception($message, $throwable->getCode(), $throwable);
        }
    }

    /**
     * Destroy the device mapper
     *
     * @param string $deviceMapperPath Path to the device mapper
     */
    private function destroyDeviceMapper(string $deviceMapperPath)
    {
        // unmount any mounted device mapper partitions
        $this->unmountPartitions($deviceMapperPath);

        try {
            // destroy any device mapper partitions
            $partitions = $this->getBlockDevicePartitions($deviceMapperPath, $this->filesystem);
            foreach ($partitions as $partition) {
                $this->logger->info('DMM0011 Destroying partition in order to destroy device mapper', ['partition' => $partition, 'deviceMapperPath' => $deviceMapperPath]);
                $this->retryHandler->executeAllowRetry(
                    function () use ($partition) {
                        $this->blockdev->flushBuffers($partition);
                        $this->dmsetup->destroy($partition);
                    },
                    self::DESTROY_RETRY_LIMIT
                );
            }

            // destroy the device mapper itself
            $this->retryHandler->executeAllowRetry(
                function () use ($deviceMapperPath) {
                    $this->blockdev->flushBuffers($deviceMapperPath);
                    $this->dmsetup->destroy($deviceMapperPath);
                },
                self::DESTROY_RETRY_LIMIT
            );
            $this->logger->info('DMM0009 Removed device mapper', ['deviceMapperPath' => $deviceMapperPath]);
        } catch (Throwable $throwable) {
            $message = 'Failed to remove device mapper';
            $this->logger->error("DMM0007 $message", ['deviceMapperPath' => $deviceMapperPath, 'error' => $throwable->getMessage()]);
            throw new Exception("$message [$deviceMapperPath]", $throwable->getCode(), $throwable);
        }
    }

    /**
     * Unmount any mounted device mapper partitions.
     *
     * TODO:
     *   This unmount logic is shared between LoopManager and DeviceMapperManager.
     *   It can be moved to a common location.
     *   Unmounting in the destroy function is a band-aid to fix clients that
     *     mount a device mapper partition, but then do not properly unmount it.
     *
     * @param string $deviceMapperPath
     */
    private function unmountPartitions(string $deviceMapperPath)
    {
        $partitions = $this->getBlockDevicePartitions($deviceMapperPath, $this->filesystem);
        foreach ($partitions as $partition) {
            $mountPoint = $this->mountManager->getDeviceMountPoint($partition);

            if ($mountPoint === false) {
                continue;
            }

            $this->logger->info('DMM0012 Unmounting mount point in order to destroy device mapper', ['mountPoint' => $mountPoint, 'deviceMapperPath' => $deviceMapperPath]);

            try {
                $this->mountManager->unmount($mountPoint);
            } catch (Throwable $throwable) {
                $this->logger->critical('DMM0013 Failed to unmount mount point', ['mountPoint' => $mountPoint, 'error' => $throwable->getMessage()]);
                throw $throwable;
            }
        }
    }

    /**
     * Get the full path given the device mapper name
     *
     * @param string $deviceMapperName
     * @return string
     */
    private function getFullPath(string $deviceMapperName): string
    {
        return self::DEVICE_MAPPER_PATH . '/' . $deviceMapperName;
    }

    /**
     * Determine if the given block device path is a device mapper
     *
     * @param string $blockDevicePath Block device path to check
     * @return bool
     */
    private function isDeviceMapper(string $blockDevicePath): bool
    {
        $deviceMapperName = basename($blockDevicePath);
        $deviceMappers = $this->dmsetup->getAll();

        $isDeviceMapper = false;
        foreach ($deviceMappers as $deviceMapper) {
            if ($deviceMapperName === $deviceMapper['displayName'] ||
                $deviceMapperName === $deviceMapper['deviceName']) {
                $isDeviceMapper = true;
                break;
            }
        }
        return $isDeviceMapper;
    }
}
