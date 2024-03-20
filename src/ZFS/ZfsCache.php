<?php
namespace Datto\ZFS;

use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Metrics;
use Datto\Metrics\Collector;
use Datto\Utility\File\LockFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use JsonSerializable;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Class for device owned caching of zfs data
 *
 * @author Justin Giacobbi <justin@datto.com>
 */
class ZfsCache implements JsonSerializable, LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DEVICE_CACHE = '/var/cache/datto/device/zfs/recoveryPointInfo';
    const CACHE_LOCK = self::DEVICE_CACHE . ".lock";

    const CURRENT_VERSION = 1;

    const VERSION = 'version';
    const ENTRIES = 'entries';
    const UPDATED = 'updated';

    const USED_SIZES = "usedSizes";

    const MKDIR_MODE = 0777;

    protected DateTimeService $dateTime;
    protected Filesystem $filesystem;
    protected LockFactory $lockFactory;
    private Collector $collector;

    /** @var ZfsCacheEntry[] */
    protected array $entries = [];

    public function __construct(
        Filesystem $filesystem,
        LockFactory $lockFactory,
        DateTimeService $dateTime,
        Collector $collector
    ) {
        $this->dateTime = $dateTime;
        $this->filesystem = $filesystem;
        $this->lockFactory = $lockFactory;
        $this->collector = $collector;
    }

    /**
     * Updates the cache with the current object contents
     *
     * @return self
     */
    public function write(): self
    {
        $lock = $this->lockFactory->getProcessScopedLock(self::CACHE_LOCK);
        $lock->exclusive();
        try {
            $this->read();
            $this->filesystem->mkdirIfNotExists(dirname(self::DEVICE_CACHE), true, self::MKDIR_MODE);
            $this->filesystem->filePutContents(self::DEVICE_CACHE, json_encode($this));
        } catch (Throwable $exception) {
            $this->collector->increment(Metrics::ZFS_CACHE_WRITE_FAIL);
            $this->filesystem->unlinkIfExists(self::DEVICE_CACHE);
            throw $exception;
        } finally {
            $lock->unlock();
        }
        return $this;
    }

    /**
     * Reads the cache and rehydrates the object
     *
     * @return self
     */
    public function read(): self
    {
        if (!$this->filesystem->exists(self::DEVICE_CACHE)) {
            return $this;
        }

        try {
            $json = $this->filesystem->fileGetContents(self::DEVICE_CACHE);
            $this->fromJson($json);
        } catch (Throwable $e) {
            $this->filesystem->unlinkIfExists(self::DEVICE_CACHE);
            $this->logger->warning('ZFC9000 ZFS cache file appeared to be corrupted. Regenerating.', ['exception'=>$e]);
        }

        return $this;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            self::VERSION => self::CURRENT_VERSION,
            self::ENTRIES => $this->entries
        ];
    }

    /**
     * Rehydrates the class from on disk json
     * @param string $json
     */
    public function fromJson(string $json)
    {
        $contents = json_decode($json, true);

        if (is_array($contents) && $contents[self::VERSION] === 1) {
            $this->fromVersionOne($contents);
        } else {
            throw new Exception(__CLASS__ . " on disk data appears corrupt");
        }
    }

    /**
     * Rehydrates the class from a specific version of the on disk json
     * @param array $contents
     * @return self
     */
    public function fromVersionOne(array $contents): self
    {
        foreach ($contents[self::ENTRIES] as $zfsPath => $entry) {
            if (isset($this->entries[$zfsPath]) &&
                $this->entries[$zfsPath]->getUpdated() > $entry[self::UPDATED]
            ) {
                continue;
            }

            $this->setEntry(
                $zfsPath,
                new ZfsCacheEntry(
                    $zfsPath,
                    $entry[self::UPDATED],
                    $entry[self::USED_SIZES]
                )
            );
        }

        return $this;
    }

    /**
     * @return ZfsCacheEntry|null
     */
    public function getEntry(string $zfsPath)
    {
        return $this->entries[$zfsPath] ?? null;
    }

    /**
     * @param string $zfsPath
     * @param ZfsCacheEntry $entries
     * @return self
     */
    public function setEntry(string $zfsPath, ZfsCacheEntry $entry): self
    {
        $this->entries[$zfsPath] = $entry;

        return $this;
    }

    /**
     * Marks an entry with an updated timestamp
     *
     * @param string $zfsPath
     * @param int|null $timestamp
     * @return self
     */
    public function markEntry(string $zfsPath, int $timestamp = null): self
    {
        if (isset($this->entries[$zfsPath])) {
            $this->entries[$zfsPath]->setUpdated($timestamp ?? $this->dateTime->getTime());
        } else {
            throw new Exception("No entry found for $zfsPath");
        }

        return $this;
    }
}
