<?php
namespace Datto\Cloud;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\File\LockFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Exception;
use JsonSerializable;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Manages and represents the speedsync cache contents
 *
 * @author Justin Giacobbi <justin@datto.com>
 */
class SpeedSyncCache implements JsonSerializable, LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DEVICE_CACHE = '/var/cache/datto/device/speedsync/recoveryPointInfo';
    const CACHE_LOCK = self::DEVICE_CACHE . ".lock";

    const CURRENT_VERSION = 1;

    const VERSION = 'version';
    const ENTRIES = 'entries';
    const UPDATED = 'updated';

    const ACTIONS = 'actions';
    const OFFSITE_POINTS = 'offsitePoints';
    const CRITICAL_POINTS = 'criticalPoints';
    const REMOTE_REPLICATING_POINTS = 'remoteReplicatingPoints';
    const REMOTE_USED_SIZE = 'remoteUsedSize';
    const QUEUED_POINTS = 'queuedPoints';

    const MKDIR_MODE = 0777;

    /** @var DateTimeService */
    protected $dateTime = null;

    /** @var Filesystem */
    protected $filesystem = null;

    /** @var LockFactory */
    protected $lockFactory = null;

    /** @var array */
    protected $actions = null;

    /** @var SpeedSyncCacheEntry[] */
    protected $entries = [];

    /**
     * @param Filesystem|null $filesystem
     * @param LockFactory|null $lockFactory
     * @param DateTimeService|null $dateTime
     */
    public function __construct(
        Filesystem $filesystem = null,
        LockFactory $lockFactory = null,
        DateTimeService $dateTime = null
    ) {
        $this->dateTime = $dateTime ?: new DateTimeService();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->lockFactory = $lockFactory ?: new LockFactory();
    }

    /**
     * Updates the cache with the current object contents. To do any processing
     *  on the cache (like cache invalidation) pass a callback. The function signature
     * is 'function(SpeedSyncCache $cache);'
     *
     * @param callable $callback
     * @return self
     */
    public function write(callable $callback = null): self
    {
        $lock = $this->lockFactory->getProcessScopedLock(self::CACHE_LOCK);
        $lock->exclusive();

        $this->read();

        if (!is_null($callback)) {
            $callback($this);
        }

        $this->filesystem->mkdirIfNotExists(dirname(self::DEVICE_CACHE), true, self::MKDIR_MODE);
        $this->filesystem->filePutContents(self::DEVICE_CACHE, json_encode($this));

        $lock->unlock();

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
            $this->logger->warning('SCA9000 SpeedSync cache file appeared to be corrupted. Regenerating.', ['exception'=>$e]);
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
            self::ACTIONS => $this->actions,
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

        if ($contents[self::VERSION] === 1) {
            $this->fromVersionOne($contents);
        } else {
            throw new Exception(__CLASS__ . " on disk data appears corrupt");
        }
    }

    /**
     * Rehydrates the class from a specific version of the on disk json
     *
     * @param array $contents
     * @return self
     */
    public function fromVersionOne(array $contents): self
    {
        if (!isset($this->actions)) {
            $this->setActions($contents[self::ACTIONS]);
        }

        foreach ($contents[self::ENTRIES] as $zfsPath => $entry) {
            if (isset($this->entries[$zfsPath]) &&
                $this->entries[$zfsPath]->getUpdated() > $entry[self::UPDATED]
            ) {
                continue;
            }

            $this->setEntry(
                $zfsPath,
                new SpeedSyncCacheEntry(
                    $zfsPath,
                    $entry[self::UPDATED],
                    $entry[self::OFFSITE_POINTS],
                    $entry[self::CRITICAL_POINTS],
                    $entry[self::REMOTE_REPLICATING_POINTS],
                    $entry[self::QUEUED_POINTS],
                    $entry[self::REMOTE_USED_SIZE]
                )
            );
        }

        return $this;
    }

    /**
     * @return array|null
     */
    public function getActions()
    {
        return $this->actions;
    }

    /**
     * @param array $actions
     *
     * @return self
     */
    public function setActions(array $actions): self
    {
        $this->actions = $actions;

        return $this;
    }

    /**
     * @return SpeedSyncCacheEntry[]
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @param string $zfsPath
     * @return SpeedSyncCacheEntry|null
     */
    public function getEntry(string $zfsPath)
    {
        return $this->entries[$zfsPath] ?? null;
    }

    /**
     * @param string $zfsPath
     * @param SpeedSyncCacheEntry $entry
     * @return self
     */
    public function setEntry(string $zfsPath, SpeedSyncCacheEntry $entry): self
    {
        $this->entries[$zfsPath] = $entry;

        return $this;
    }

    /**
     * @param string $zfsPath
     * @return self
     */
    public function deleteEntry(string $zfsPath): self
    {
        unset($this->entries[$zfsPath]);

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
