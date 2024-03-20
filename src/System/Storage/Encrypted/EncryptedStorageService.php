<?php

namespace Datto\System\Storage\Encrypted;

use Datto\Cloud\EncryptedStorageClient;
use Datto\Log\SanitizedException;
use Datto\Common\Utility\Filesystem;
use Datto\System\Storage\StorageDevice;
use Datto\System\Storage\StorageService;
use Datto\Utility\Block\Dmsetup;
use Datto\Utility\Block\Luks;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Throwable;

/**
 * Service class to interact with encrypted disks.
 *
 * @author Marcus Recck <mr@datto.com>
 * @author Chad Kosie <ckosie@datto.com>
 */
class EncryptedStorageService
{
    const LUKS_DRIVE_MAPPER_FORMAT = 'luks-%s';
    const LUKS_DRIVE_MAPPER_PATH_FORMAT = '/dev/mapper/%s';

    const KEY_FILE = '/dev/shm/.tmpPass';

    const FIXED_KEY_SLOT = 0;
    const GENERATED_KEY_SLOT = 1;

    const GENERATED_KEY_STRING_LENGTH = 64;

    /** @var Filesystem */
    private $fileSystem;

    /** @var StorageService */
    private $storageService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Dmsetup */
    private $dmsetup;

    /** @var Luks */
    private $luks;

    /** @var EncryptedStorageClient */
    private $encryptedStorageClient;

    /**
     * @param Filesystem $fileSystem
     * @param StorageService $storageService
     * @param DeviceLoggerInterface $logger
     * @param Dmsetup $dmsetup
     * @param Luks $luks
     * @param EncryptedStorageClient $encryptedStorageClient
     */
    public function __construct(
        Filesystem $fileSystem,
        StorageService $storageService,
        DeviceLoggerInterface $logger,
        Dmsetup $dmsetup,
        Luks $luks,
        EncryptedStorageClient $encryptedStorageClient
    ) {
        $this->fileSystem = $fileSystem;
        $this->storageService = $storageService;
        $this->logger = $logger;
        $this->dmsetup = $dmsetup;
        $this->luks = $luks;
        $this->encryptedStorageClient = $encryptedStorageClient;
    }

    /**
     * Handle adding in a generated encryption key to encrypted storage disks. This will be used as a one-time manual
     * step to all the generated key to all encrypted drives.
     *
     * Once this operation is done, the disks can be unlocked with both the fixed key and the generated key.
     *
     * @param string $fixedKey
     * @param StorageDevice[]|null $encryptedDisks Only apply generated key to the specified disks. If null is passed
     *                                             in, query the system for all encrypted disks.
     */
    public function addMissingGeneratedKey(string $fixedKey, array $encryptedDisks = null)
    {
        try {
            $this->logger->info('ESS0073 Fetching generated key');
            $generatedKey = $this->getGeneratedKey();
        } catch (Throwable $e) {
            $this->logger->error('ESS0036 Could not get generated key', ['exception' => $e]);
            throw new SanitizedException($e, isset($generatedKey) ? [$generatedKey] : null);
        }

        if ($encryptedDisks === null) {
            try {
                $this->logger->info('ESS0072 Fetching all encrypted drives');
                $encryptedDisks = $this->getEncryptedDisks();
            } catch (Throwable $e) {
                $this->logger->error('ESS0040 Could not get list of encrypted drives', ['exception' => $e]);
                throw $e;
            }
        }

        $this->logger->info(
            'ESS0034 Checking if generated key needs to be added to drive(s)',
            ['deviceNames' => $this->getDeviceNames($encryptedDisks)]
        );

        $failed = [];

        foreach ($encryptedDisks as $encryptedDisk) {
            try {
                $this->logger->info(
                    'ESS0002 Checking if drive needs to have generated key added',
                    ['diskName' => $encryptedDisk->getName()]
                );

                if ($this->testKey($encryptedDisk, $generatedKey, self::GENERATED_KEY_SLOT)) {
                    $this->logger->notice(
                        'ESS0003 Drive already has generated key added to slot, skipping',
                        ['diskName' => $encryptedDisk->getName()]
                    );

                    continue;
                }

                if (!$this->testKey($encryptedDisk, $fixedKey, self::FIXED_KEY_SLOT)) {
                    $this->logger->warning(
                        'ESS0004 Drive does not have a fixed key in fixed key slot, marking as failed',
                        ['diskName' => $encryptedDisk->getName(), 'fixedKeySlot' => self::FIXED_KEY_SLOT]
                    );

                    $failed[] = $encryptedDisk;
                    continue;
                }

                if ($this->isUsedKeySlot($encryptedDisk, self::GENERATED_KEY_SLOT)) {
                    $this->logger->info(
                        'ESS0005 Drive  has unknown key in generated key slot, removing it',
                        ['diskName' => $encryptedDisk->getName(), 'fixedKeySlot' => self::GENERATED_KEY_SLOT]
                    );

                    $this->removeKey($encryptedDisk, $fixedKey, self::GENERATED_KEY_SLOT);
                }

                $this->logger->info(
                    'ESS0006 Drive cannot be unlocked using generated key, adding it ...',
                    ['diskName' => $encryptedDisk->getName()]
                );

                $this->addKey($encryptedDisk, $fixedKey, $generatedKey, self::GENERATED_KEY_SLOT);
            } catch (Throwable $e) {
                $this->logger->error(
                    'ESS0007 Could not add generated key to disk',
                    ['diskName' => $encryptedDisk->getName(), 'exception' => $e]
                );
                $failed[] = $encryptedDisk;
            }
        }

        if (!empty($failed)) {
            $deviceNames = $this->getDeviceNames($failed);
            $this->logger->error('ESS0008 Could not add generated key to drive(s)', ['failedDeviceNames' => $deviceNames]);

            throw new Exception('Could not add generated key to drive(s), please check the following: '
                . implode(',', $deviceNames));
        } else {
            $this->logger->info('ESS0037 All drives have generated key');
        }
    }

    /**
     * Loop over all disks and determine if any are encrypted and unlocked.
     *
     * @return StorageDevice[]
     */
    public function getLockedEncryptedDisks(): array
    {
        return array_values(array_filter($this->getEncryptedDisks(), function (StorageDevice $disk) {
            return !$this->hasDiskBeenUnlocked($disk);
        }));
    }

    /**
     * Unlock all disks that remain encrypted
     *
     * @param string $key
     */
    public function unlockAllDisks(string $key)
    {
        $this->logger->info('ESS0039 Unlocking all drives ...');

        try {
            $encryptedDisks = $this->getLockedEncryptedDisks();
        } catch (Throwable $e) {
            $this->logger->error('ESS0051 Could not get encrypted drives to unlock', ['exception' => $e]);
            throw new SanitizedException($e, [$key]);
        }

        $failed = [];

        foreach ($encryptedDisks as $encryptedDisk) {
            try {
                $this->unlockDisk($encryptedDisk, $key);
            } catch (Throwable $e) {
                $this->logger->error('ESS0043 Failed to unlock drive', ['exception' => $e]);
                $failed[] = $encryptedDisk;
            }
        }

        if (!empty($failed)) {
            $failedDeviceNames = $this->getDeviceNames($failed);
            $this->logger->error('ESS0053 Could not unlock all drives', ['failedDeviceNames' => $failedDeviceNames]);

            throw new Exception('Could not unlock all drives, please check the following: '
                . implode(',', $failedDeviceNames));
        } else {
            $this->logger->info('ESS0042 Successfully unlocked all drives');
        }
    }

    /**
     * Encrypt a disk
     *
     * @param StorageDevice $disk
     * @param string $key
     * @param bool $addGeneratedKey
     */
    public function encryptDisk(StorageDevice $disk, string $key, bool $addGeneratedKey = true)
    {
        $this->fileSystem->filePutContents(self::KEY_FILE, $key);

        try {
            $this->logger->info('ESS0013 Attempting to encrypt drive', ['diskName' => $disk->getName()]);
            $this->luks->encrypt($disk->getName(), self::KEY_FILE, self::FIXED_KEY_SLOT);
            $this->logger->info('ESS0014 Drive encrypted successfully.', ['diskName' => $disk->getName()]);

            if ($addGeneratedKey) {
                $this->addMissingGeneratedKey($key, [$disk]);
            }
        } catch (ProcessFailedException $e) {
            $this->logger->error(
                'ESS0015 Encrypt command was unsuccessful',
                ['exception' => $e, 'processOutput' => $e->getProcess()->getOutput(), 'processErrorOutput' => $e->getProcess()->getErrorOutput()]
            );
            throw new SanitizedException($e, [$key]);
        } catch (Throwable $e) {
            $this->logger->error('ESS0016 Encrypt command was unsuccessful');
            throw new SanitizedException($e, [$key]);
        } finally {
            $this->fileSystem->unlink(self::KEY_FILE);
        }
    }

    /**
     * Unlock a disk by exposing the plaintext data via a devmapper.
     *
     * @param StorageDevice $disk
     * @param string $key
     * @return string
     */
    public function unlockDisk(StorageDevice $disk, string $key)
    {
        try {
            $this->logger->info('ESS0017 Attempting to unlock drive', ['diskName' => $disk->getName()]);

            $luksName = $this->getLuksFormat($disk);
            $deviceMapperPath = $this->getDeviceMapperPath($disk);

            if ($this->hasDiskBeenUnlocked($disk)) {
                $this->logger->info('ESS0018 Drive was already unlocked and is available through device mapper', ['diskName' => $disk->getName(), 'deviceMapperPath' => $deviceMapperPath]);

                return $deviceMapperPath;
            }

            $this->luks->unlock($disk->getName(), $luksName, $key);

            $this->logger->info('ESS0019 Drive unlocked successfully and is available through device mapper', ['diskName' => $disk->getName(), 'deviceMapperPath' => $deviceMapperPath]);

            return $deviceMapperPath;
        } catch (ProcessFailedException $e) {
            $this->logger->error(
                'ESS0020 unlock command was unsuccessful',
                ['exception' => $e, 'processOutput' => $e->getProcess()->getOutput(), 'processErrorOutput' => $e->getProcess()->getErrorOutput()]
            );
            throw new SanitizedException($e, [$key]);
        } catch (Throwable $e) {
            $this->logger->error('ESS0021 unlock command was unsuccessful');
            throw new SanitizedException($e, [$key]);
        }
    }

    /**
     * @param StorageDevice $disk
     * @return string
     */
    public function unlockDiskUsingGeneratedKey(StorageDevice $disk)
    {
        try {
            $key = $this->getGeneratedKey();
            $this->logger->info('ESS0035 Attempting to unlock drive using generated key ...', ['diskName' => $disk->getName()]);
            return $this->unlockDisk($disk, $key);
        } catch (Throwable $e) {
            throw new SanitizedException($e, isset($key) ? [$key] : null);
        }
    }

    /**
     * Unlock all disks using generated key.
     */
    public function unlockAllDisksUsingGeneratedKey()
    {
        $this->logger->info('ESS0041 Attempting to unlock all drives using generated key ...');

        try {
            $key = $this->getGeneratedKey();
        } catch (Throwable $e) {
            $this->logger->error('ESS0050 Could not get generated key to unlock all drives', ['exception' => $e]);
            throw new SanitizedException($e, isset($key) ? [$key] : null);
        }

        $this->unlockAllDisks($key);
    }

    /**
     * Check if a disk is encrypted
     *
     * @param StorageDevice $disk
     *
     * @return bool
     */
    public function isDiskEncrypted(StorageDevice $disk): bool
    {
        return $this->luks->encrypted($disk->getName());
    }

    /**
     * @param StorageDevice $disk
     * @param string $anyExistingKey Provide a key that matches any of the existing keys in any slot.
     * @param string $newKey
     * @param int $slot
     */
    public function addKey(StorageDevice $disk, string $anyExistingKey, string $newKey, int $slot)
    {
        if ($slot === self::FIXED_KEY_SLOT) {
            throw new Exception('Cannot overwrite LUKS key slot ' . self::FIXED_KEY_SLOT);
        }

        if (!$this->isDiskEncrypted($disk)) {
            throw new Exception('Cannot add LUKS key to non-encrypted disk');
        }

        try {
            $this->logger->info('ESS0022 Adding key to disk', ['diskName' => $disk->getName(), 'slot' => $slot]);
            $this->luks->addKey($disk->getName(), $anyExistingKey, $newKey, $slot);
            $this->logger->info('ESS0023 Key was successfully added to disk', ['diskName' => $disk->getName(), 'slot' => $slot]);
        } catch (ProcessFailedException $e) {
            $this->logger->error(
                'ESS0024 add key command was unsuccessful',
                ['exception' => $e, 'processOutput' => $e->getProcess()->getOutput(), 'processErrorOutput' => $e->getProcess()->getErrorOutput()]
            );
            throw new SanitizedException($e, [$anyExistingKey, $newKey]);
        } catch (Throwable $e) {
            $this->logger->error('ESS0025 add key command was unsuccessful');
            throw new SanitizedException($e, [$anyExistingKey, $newKey]);
        }
    }

    /**
     * Check if a disk has been unlocked.
     *
     * A disk is considered unlocked if there exists a dev mapper for it,
     * thus we can rely on the exit code of `dmsetup info <device>`
     *
     * @param StorageDevice $disk
     *
     * @return bool
     */
    public function hasDiskBeenUnlocked(StorageDevice $disk): bool
    {
        $mapper = $this->getLuksFormat($disk);
        return $this->dmsetup->exists($mapper);
    }

    /**
     * Get the used LUKS key slots for a disk.
     *
     * @param StorageDevice $disk
     * @return int[]
     */
    public function getUsedKeySlots(StorageDevice $disk): array
    {
        return $this->luks->getEnabledKeySlots($disk->getName());
    }

    /**
     * @param StorageDevice $disk
     * @return string
     */
    public function getDeviceMapperPath(StorageDevice $disk): string
    {
        return sprintf(self::LUKS_DRIVE_MAPPER_PATH_FORMAT, $this->getLuksFormat($disk));
    }

    /**
     * Test if the generated key works on an array of disks.
     *
     * @param StorageDevice[] $disks
     * @return bool[]
     */
    public function testGeneratedKeyMany(array $disks): array
    {
        $works = [];

        if (!empty($disks)) {
            $key = $this->getGeneratedKey();
            foreach ($disks as $disk) {
                $works[$disk->getName()] = $this->testKey($disk, $key, self::GENERATED_KEY_SLOT);
            }
        }

        return $works;
    }

    /**
     * Get all disks that are LUKS encrypted.
     *
     * @return StorageDevice[]
     */
    public function getEncryptedDisks(): array
    {
        $disks = $this->storageService->getPhysicalDevices();
        $encryptedDisks = [];

        foreach ($disks as $disk) {
            if ($this->isDiskEncrypted($disk)) {
                $encryptedDisks[] = $disk;
            }
        }

        return $encryptedDisks;
    }


    /**
     * @param StorageDevice $disk
     * @param int $slot
     * @return bool
     */
    private function isUsedKeySlot(StorageDevice $disk, int $slot): bool
    {
        return in_array($slot, $this->getUsedKeySlots($disk), true);
    }

    /**
     * @param StorageDevice $disk
     * @param string $anyExistingKey Provide a key that matches any of the existing keys in any slot.
     * @param int $slot
     */
    private function removeKey(StorageDevice $disk, string $anyExistingKey, int $slot)
    {
        if ($slot === self::FIXED_KEY_SLOT) {
            throw new Exception('Cannot remove LUKS key slot ' . self::FIXED_KEY_SLOT);
        }

        if (!$this->isDiskEncrypted($disk)) {
            throw new Exception('Cannot remove LUKS key from non-encrypted disk');
        }

        try {
            $this->logger->info('ESS0026 Removing key from disk', ['diskName' => $disk->getName(), 'slot' => $slot]);
            $this->luks->killSlot($disk->getName(), $anyExistingKey, $slot);
        } catch (ProcessFailedException $e) {
            $this->logger->error(
                'ESS0027 remove key command was unsuccessful',
                ['exception' => $e, 'processOutput' => $e->getProcess()->getOutput(), 'processErrorOutput' => $e->getProcess()->getErrorOutput()]
            );
            throw new SanitizedException($e, [$anyExistingKey]);
        } catch (Throwable $e) {
            $this->logger->error('ESS0028 remove key command was unsuccessful');
            throw new SanitizedException($e, [$anyExistingKey]);
        }
    }

    /**
     * Test to see if a disk can be unlocked with a key.
     *
     * @param StorageDevice $disk
     * @param string $key
     * @param int|null $slot If null, test all slots for a match.
     * @return bool
     */
    private function testKey(StorageDevice $disk, string $key, int $slot = null): bool
    {
        return $this->luks->testKey($disk->getName(), $key, $slot);
    }

    /**
     * @param StorageDevice $disk
     * @return string
     */
    private function getLuksFormat(StorageDevice $disk): string
    {
        $displayName = $disk->getShortName();

        if (!empty($disk->getSerial())) {
            $displayName = $disk->getSerial();
        }

        return sprintf(self::LUKS_DRIVE_MAPPER_FORMAT, $displayName);
    }

    /**
     * @return string
     */
    private function getGeneratedKey(): string
    {
        return $this->encryptedStorageClient->getKey();
    }

    /**
     * @param StorageDevice[] $disks
     * @return string[]
     */
    private function getDeviceNames(array $disks): array
    {
        $toNameCallable = function (StorageDevice $disk) {
            return $disk->getName();
        };

        return array_map($toNameCallable, $disks);
    }
}
