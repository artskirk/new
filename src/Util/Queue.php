<?php

namespace Datto\Util;

use Datto\Config\DeviceState;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\File\LockFactory;

/**
 * Locking queue.
 *
 * @author Chad Kosie <ckosie@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class Queue
{
    const MAX_WAIT_SECONDS = 15;
    const BASE_SHM_PATH = '/dev/shm/';

    /** @var string */
    private $queueName;

    /** @var DeviceState */
    private $deviceState;

    /** @var LockFactory */
    private $lockFactory;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        string $queueName,
        DeviceState $deviceState,
        Filesystem $filesystem,
        LockFactory $lockFactory
    ) {
        $this->queueName = $queueName;
        $this->deviceState = $deviceState;
        $this->filesystem = $filesystem;
        $this->lockFactory = $lockFactory;
    }

    /**
     * Note: This is expensive since it needs to read the entire queue from disk.
     * @return int
     */
    public function size()
    {
        return count($this->read());
    }

    /**
     * Clears the queue of all events if the queue file size is larger than the desired size.
     *
     * @param int $maxFilesize The maximum file size of the queue in bytes.
     * @return bool True if the queue was cleared, false if it wasn't
     */
    public function clearIfLargerThan(int $maxFilesize): bool
    {
        $this->lock();

        $filesize = $this->filesystem->getSize($this->deviceState->getKeyFilePath($this->queueName));

        if ($filesize > $maxFilesize) {
            $this->save([]);
            return true;
        }

        $this->unlock();
        return false;
    }

    /**
     * Modify the entire queue.
     *
     * @param callable $callable
     */
    public function modify(callable $callable)
    {
        $this->lock();
        $modified = $callable($this->read());
        $this->save($modified);
        $this->unlock();
    }

    /**
     * Enqueue an item.
     *
     * @param $newItem
     */
    public function enqueue($newItem)
    {
        $this->lock();
        $this->append($newItem);
        $this->unlock();
    }

    /**
     * @param array $newItems
     */
    public function enqueueMany(array $newItems)
    {
        $this->lock();

        foreach ($newItems as $newItem) {
            $this->append($newItem);
        }

        $this->unlock();
    }

    /**
     * Dequeue an item.
     *
     * @return mixed|null
     */
    public function dequeue()
    {
        $this->lock();

        $items = $this->read();

        if (!empty($items)) {
            $item = array_shift($items);
            $this->save($items);
        } else {
            $item = null;
        }

        $this->unlock();

        return $item;
    }

    /**
     * Dequeue several items at once.
     */
    public function dequeueMany(int $maximumItems = null): array
    {
        $this->lock();
        $items = $this->read();

        if ($maximumItems === null) {
            $splicedItems = array_splice($items, 0);
        } else {
            $splicedItems = array_splice($items, 0, $maximumItems);
        }

        $this->save($items);
        $this->unlock();

        return $splicedItems;
    }

    private function lock()
    {
        $this->getLock()->assertExclusiveAllowWait(self::MAX_WAIT_SECONDS);
    }

    private function unlock()
    {
        $this->getLock()->unlock();
    }

    private function getLock()
    {
        return $this->lockFactory->getProcessScopedLock(self::BASE_SHM_PATH . $this->queueName . '.lock');
    }

    private function save(array $items)
    {
        $lines = array_map('json_encode', $items);
        $this->deviceState->setRaw($this->queueName, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function append($item)
    {
        $path = $this->deviceState->getKeyFilePath($this->queueName);
        $this->filesystem->filePutContents($path, json_encode($item) . PHP_EOL, FILE_APPEND);
    }

    private function read(): array
    {
        $lines = explode(PHP_EOL, $this->deviceState->getRaw($this->queueName, ''));

        $decodedLines = array_map(
            static function ($line) {
                return json_decode($line, true);
            },
            $lines
        );

        // Protect against malformed lines by only returning those that decode properly
        return array_values(array_filter($decodedLines));
    }
}
