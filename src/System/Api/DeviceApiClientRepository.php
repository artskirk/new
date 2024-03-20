<?php

namespace Datto\System\Api;

use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Exception;

/**
 * Repository to cache DeviceClient connection data to disk.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class DeviceApiClientRepository
{
    const CONNECTION_FILE = '/dev/shm/device.connection';

    /** @var Filesystem */
    protected $filesystem;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /**
     * @param Filesystem $filesystem
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        Filesystem $filesystem,
        DeviceLoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->logger = $logger;
    }

    /**
     * Saves the DeviceClient connection data to disk.
     *
     * @param string $connectionData Serialized connection data.
     */
    public function save(string $connectionData)
    {
        if ($this->filesystem->filePutContents(self::CONNECTION_FILE, $connectionData) === false) {
            throw new Exception("Disk error saving device client connection.");
        }
    }

    /**
     * Loads the DeviceClient connection data from disk.
     *
     * @return string Serialized connection data.
     */
    public function load(): string
    {
        if ($this->filesystem->exists(self::CONNECTION_FILE)) {
            $connectionData = $this->filesystem->fileGetContents(self::CONNECTION_FILE);
            if ($connectionData !== false) {
                return $connectionData;
            }
        }
        throw new Exception('No device client connection is available.');
    }

    /**
     * Deletes the saved DeviceClient connection data from disk.
     */
    public function delete()
    {
        if ($this->filesystem->exists(self::CONNECTION_FILE) &&
            $this->filesystem->unlink(self::CONNECTION_FILE) === false) {
            throw new Exception("Disk error deleting device client connection.");
        }
    }
}
