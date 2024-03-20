<?php

namespace Datto\Config;

use Datto\Core\Configuration\Config;
use Datto\Core\Configuration\ConfigRecordInterface;
use Datto\Common\Utility\Filesystem;

/**
 * This base class implements all the common functionality for key file reads
 * and writes used by AgentConfig, DeviceConfig, and LocalConfig.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class FileConfig implements Config
{
    const ERROR_RESULT = '1';

    /** @var Filesystem */
    protected $filesystem;

    /** @var string */
    protected $baseConfigPath;

    /**
     * @param string $baseConfigPath
     * @param Filesystem $filesystem
     */
    public function __construct(string $baseConfigPath, Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->baseConfigPath = $baseConfigPath;
    }

    /**
     * Reads the value from a key file.
     * @param string $key
     * @param mixed $default
     * @return mixed Returns one of the following:
     *    static::ERROR_RESULT if file is empty or contains only whitespace;
     *    $default if the file does not exist or an error occurred;
     *    contents of the file trimmed of leading and trailing whitespace.
     */
    public function get(string $key = null, $default = false)
    {
        $value = $this->getRaw($key);
        if ($value === false) {
            $value = $default;
        } else {
            $value = trim($value);
            if ($value === '') {
                $value = static::ERROR_RESULT;
            }
        }
        return $value;
    }

    /**
     * Writes a value to a key file.
     * Writes one of the following:
     *   If $value is an array or an object, writes the serialized string;
     *   If $value is empty (PHP "empty()"), deletes the key file;
     *   Otherwise writes $value converted to a string.
     * @param string $key
     * @param mixed $value
     * @return bool TRUE if successful, FALSE if failed.
     */
    public function set(string $key = null, $value): bool
    {
        if (is_array($value) || is_object($value)) {
             // need to convert the quotes so they aren't lost when we write to the ini file
            $value = str_replace('"', '\"', serialize($value));
        }

        if (empty($value)) {
            return $this->clear($key);
        } else {
            return $this->setRaw($key, $value);
        }
    }

    /**
     * Tests if a key file exists.
     * IMPORTANT: This function uses "file_exists()" to test if a file exists.
     * The results of this are cached by PHP, and this function could still
     * return true after another process deletes the key file.  See PHP's
     * "clearstatcache()" function for more information.
     * @param string $key
     * @return bool TRUE if key exists, FALSE otherwise.
     */
    public function has(string $key = null): bool
    {
        $keyFilePath = $this->getValidatedKeyFilePath($key);
        return $keyFilePath && $this->filesystem->exists($keyFilePath);
    }

    /**
     * Clears (deletes) a key file.
     * @param string $key
     * @return bool TRUE if successful or the key file did not exist,
     *    FALSE if failed.
     */
    public function clear(string $key = null): bool
    {
        $keyFilePath = $this->getValidatedKeyFilePath($key);
        return $keyFilePath && (@$this->filesystem->unlink($keyFilePath) || !$this->filesystem->exists($keyFilePath));
    }

    /**
     * Reads the value of a key file as a string.
     * @param string $key
     * @param mixed $default Value to return if the get fails for any reason.
     * @return string|mixed File contents or $default on failure.
     */
    public function getRaw(string $key = null, $default = false)
    {
        $keyFilePath = $this->getValidatedKeyFilePath($key);
        if ($keyFilePath && $this->filesystem->exists($keyFilePath)) {
            $value = @$this->filesystem->fileGetContents($keyFilePath);
            if ($value !== false) {
                return $value;
            }
        }
        return $default;
    }

    /**
     * Writes a string value to a key file.
     * This will create the directory path to the key file if necessary.
     * The write operation is atomic and can be used safely with "getRaw()"
     * across multiple processes.
     * @param string $key
     * @param string $value
     * @return bool TRUE if successful, FALSE if failed.
     */
    public function setRaw(string $key = null, $value): bool
    {
        $keyFilePath = $this->getValidatedKeyFilePath($key);
        if ($keyFilePath) {
            @$this->filesystem->mkdirIfNotExists(dirname($keyFilePath), true, 0777);
            if (@$this->filesystem->putAtomic($keyFilePath, $value) === strlen($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Touches a key file.
     * This will create the directory path to the key file if necessary.
     * @param string $key
     * @return bool TRUE if successful, FALSE if failed.
     */
    public function touch(string $key = null): bool
    {
        $keyFilePath = $this->getValidatedKeyFilePath($key);
        if ($keyFilePath) {
            @$this->filesystem->mkdirIfNotExists(dirname($keyFilePath), true, 0777);
            if (@$this->filesystem->touch($keyFilePath)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    public function loadRecord(ConfigRecordInterface $record): bool
    {
        if ($this->has($record->getKeyName())) {
            $raw = $this->getRaw($record->getKeyName());
            $record->unserialize($raw);
            return true;
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    public function saveRecord(ConfigRecordInterface $record)
    {
        $this->setRaw($record->getKeyName(), $record->serialize());
    }

    /**
     * @inheritdoc
     */
    public function clearRecord(ConfigRecordInterface $record)
    {
        $this->clear($record->getKeyName());
    }

    /**
     * Gets the full path and name of the key file given its name.
     * @param string $key Key name.
     * @return string Filesystem path to key file.
     */
    public function getKeyFilePath($key)
    {
        return $this->baseConfigPath . '/' . $key;
    }

    /**
     * Gets the validated key file path from the key file name.
     * @param string $key
     * @return string|false Key file path or false if invalid key name.
     */
    private function getValidatedKeyFilePath($key)
    {
        if ($key === null || $key === '') {
            return false;
        }
        return $this->getKeyFilePath($key);
    }
}
