<?php

namespace Datto\Restore;

use Datto\Asset\Serializer\Serializer;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\Serializer\LegacyUIRestoresSerializer;

/**
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class RestoreRepository // TODO:: Implements ConfigRepository ?
{
    const CONFIG_FILE = '/datto/config/UIRestores';

    /** @var Filesystem */
    protected $filesystem;

    /** @var Serializer */
    protected $serializer;

    /** @var string */
    protected $checksum;

    public function __construct(Filesystem $filesystem, LegacyUIRestoresSerializer $serializer)
    {
        $this->filesystem = $filesystem;
        $this->serializer = $serializer;
        $this->checksum = '';
    }

    /**
     * Returns whether or not the config file exists.
     *
     * @return bool
     */
    public function exists()
    {
        return $this->filesystem->exists(self::CONFIG_FILE);
    }

    /**
     * Destroys the model for a particular share
     */
    public function destroy()
    {
        if (@$this->filesystem->unlink(self::CONFIG_FILE)) {
            $this->checksum = '';
        }
    }

    /**
     * Stores a model
     *
     * @param Restore[] $restores
     *
     * @return bool
     */
    public function save(array $restores)
    {
        $fileData = serialize($this->serializer->serialize($restores));

        if ($this->exists()) {
            $persistedFile = $this->filesystem->fileGetContents(self::CONFIG_FILE);
            $hash = hash('md5', $persistedFile);

            // There was a change made to the file by some other process - we must account for this
            if ($persistedFile !== false && $hash !== $this->checksum) {
                // Merge the contents
                $fileData = $this->mergeExistingFiles($fileData);
            }
        }

        // Persist the serialized array
        $result = $this->filesystem->filePutContents(self::CONFIG_FILE, $fileData);

        if ($result !== false) {
            return true;
        }

        return false;
    }

    /**
     * Retrieve file contents and then return Restore objects
     *
     * @return Restore[]
     */
    public function getAll()
    {
        $fileContents = @$this->filesystem->fileGetContents(self::CONFIG_FILE);

        if (!$fileContents || empty($fileContents)) {
            return array();
        }

        $restores = $this->serializer->unserialize(unserialize($fileContents, ['allowed_classes' => false]));
        $this->checksum = hash('md5', $fileContents);

        return $restores;
    }

    /**
     * @param $contents
     *
     * @return string
     */
    private function mergeExistingFiles($contents)
    {
        $newArr = unserialize($contents, ['allowed_classes' => false]);
        $existingArr = unserialize($this->filesystem->fileGetContents(self::CONFIG_FILE), ['allowed_classes' => false]);
        $mergedArr = array_replace_recursive($existingArr, $newArr);

        return serialize($mergedArr);
    }
}
