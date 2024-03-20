<?php

namespace Datto\Asset;

use Datto\Asset\Serializer\Serializer;
use Datto\Config\AgentConfigFactory;
use Datto\Iscsi\IscsiTargetException;
use Datto\Log\DeviceLogger;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerFactory;
use Throwable;

/**
 * Generic repository that supports reading and writing to
 * multiple backend files.
 *
 * This is particularly handy for multi-file config backends
 * such as the agent and its dotfiles (.interval, .agentInfo, ...).
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class AssetRepository implements Repository
{
    /** @var Serializer The serializer to use for reading/writing the config files */
    protected $serializer;

    /** @var AgentConfigFactory */
    protected $agentConfigFactory;

    /** @var string Base directory for the config files  */
    protected $baseDir;

    /** @var string File extension to use to list/glob assets (e.g. 'agentInfo') */
    protected $mainExtension;

    /** @var string[] List of extensions to read from / write to */
    protected $extensions;

    /** @var string[] List of extensions to treat as flag files */
    protected $flagExtensions;

    /** @var AssetRepositoryFileCache Per agent file contents for all loaded agents*/
    protected $fileCache;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        Serializer $serializer,
        $baseDir,
        $mainExtension,
        array $extensions,
        array $flagExtensions,
        AssetRepositoryFileCache $fileCache = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->serializer = $serializer;
        $this->baseDir = $baseDir;
        $this->mainExtension = $mainExtension;
        $this->extensions = $extensions;
        $this->flagExtensions = $flagExtensions;
        $this->fileCache = $fileCache ?: new AssetRepositoryFileCache();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
    }

    /**
     * Stores an asset using the underlying config file(s).
     *
     * Using the serializer:
     *   This method utilizes the serializer to serialize the asset
     *   into a file array in the format:
     *
     *   $fileArray = array(
     *     'agentInfo' => 'a:3:{..',
     *     'interval' => '180',
     *     'backupPause' => '',
     *     ..
     *   );
     *
     * Deleting files:
     *   The serializer can instruct this method to delete/unlink a
     *   file by returning 'null'.
     *
     * Partial writes / saving changes only:
     *   If the asset has been previously retrieved with the get()
     *   method through this repository, the file contents will only
     *   be written to disk if they have changed between the load
     *   process and this save method call. This feature uses the
     *   file cache ($fileCache).
     *
     * @param Asset $asset
     */
    public function save(Asset $asset)
    {
        $fileCache = $this->fileCache->get();

        $fileArray = $this->serializer->serialize($asset);
        $keyName = $asset->getKeyName();

        $agentConfig = $this->agentConfigFactory->create($asset->getKeyName());

        $this->preSave($keyName, $fileArray);

        foreach ($fileArray as $extension => $contents) {
            // treat flag files differently than data files
            $isFlag = in_array($extension, $this->flagExtensions);

            if ($isFlag) {
                $currentValue = $fileArray[$extension] ?? false;
                $previousValue = $fileCache[$keyName][$extension] ?? false;

                if (($currentValue === true) && ($previousValue !== true)) {
                    $agentConfig->setRaw($extension, null);
                } elseif (($currentValue !== true) && ($previousValue === true)) {
                    $agentConfig->clear($extension);
                }

                // When an asset it loaded, we read all the keyfiles from the list in their Repository class. These
                // lists are different between agents and shares. Some serializers are used for both agents and shares,
                // but have keyfiles that are only loaded by one or the other (such as "archived" and
                // "backupPauseWhileMetered"). We want to skip saving keyfiles that we don't load for this asset
            } elseif (in_array($extension, $this->extensions)) {
                $mergedContents = $this->mergeExistingFile($keyName, $extension, $contents);

                $fileIsCached = isset($fileCache[$keyName]) && array_key_exists($extension, $fileCache[$keyName]);
                $hasIdenticalCachedContent = $fileIsCached && $fileCache[$keyName][$extension] === $mergedContents;

                // An optimization: If we have cached content, and we can coerce it into the same
                // primitive type as the content we want to write, we should check if it's semantically
                // the same, not just === the same. This is about halfway from using ==, which
                // is too permissive for our purposes - we don't want to get caught thinking that random
                // file strings are true!
                if ($fileIsCached) {
                    if (is_bool($mergedContents) &&
                        $mergedContents === filter_var($fileCache[$keyName][$extension], FILTER_VALIDATE_BOOL, ['flags' => FILTER_NULL_ON_FAILURE])) {
                        $hasIdenticalCachedContent = true;
                    }
                    if (is_int($mergedContents) &&
                        $mergedContents === filter_var($fileCache[$keyName][$extension], FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE])) {
                        $hasIdenticalCachedContent = true;
                    }
                }

                if (!$hasIdenticalCachedContent) {
                    $shouldDeleteFile = is_null($mergedContents);

                    if ($shouldDeleteFile) {
                        $agentConfig->clear($extension);
                    } else {
                        $agentConfig->setRaw($extension, $mergedContents);
                    }
                }
            }
        }

        $this->fileCache->set($keyName, $fileArray);

        // we want to create this file on first save only (usually asset creation) todo move to pairing/creation
        if (!$agentConfig->has('dateAdded')) {
            $agentConfig->setRaw('dateAdded', time());
        }
    }

    /**
     * Check if an asset with the given name exists.
     * This checks the filesystem for the config file with the 'mainExtension'.
     *
     * @param string $keyName The key name of the asset
     * @param string|null $type Optional AssetType to filter
     * @return bool True if the asset exists, false otherwise
     */
    public function exists($keyName, $type = null): bool
    {
        if (empty($keyName)) {
            return false;
        }
        return $this->checkType($keyName, true, true, $type);
    }

    /**
     * Destroys the model for a particular asset.
     *
     * This deletes all the config files for this asset.
     *
     * @param string $keyName The key name of the asset
     */
    public function destroy($keyName)
    {
        $agentConfig = $this->agentConfigFactory->create($keyName);

        foreach ($this->extensions as $extension) {
            $agentConfig->clear($extension);
        }
    }

    /**
     * Read the config file(s) for a given asset and create a
     * corresponding Asset object. This uses the underlying serializer.
     *
     * @param string $keyName The key name of the asset
     * @return Asset The desired asset
     */
    public function get($keyName)
    {
        $fileArray = $this->readConfigFiles($keyName);

        if (!$fileArray || !isset($fileArray[$this->mainExtension])) {
            throw new AssetException("Unable to load $keyName. Could not get contents.");
        }

        $this->fileCache->set($keyName, $fileArray);
        return $this->serializer->unserialize($fileArray);
    }

    /**
     * Read all the assets and unserialize them into Asset objects.
     * This uses the get() function for all available assets.
     *
     * @param bool $getReplicated Whether replicated assets will be included
     * @param bool $getArchived Whether archived assets will be included
     * @param string|null $type An AssetType constant. Only get assets that match this type.
     * @return Asset[] List of assets
     */
    public function getAll(bool $getReplicated = true, bool $getArchived = true, ?string $type = null)
    {
        $keyNames = $this->getAllNames($getReplicated, $getArchived, $type);
        $assets = [];

        foreach ($keyNames as $keyName) {
            try {
                $assets[] = $this->get($keyName);
            } catch (AssetException $e) {
                $this->logger->info('ASR0001 Cannot load asset', ['assetKey' => $keyName, 'exception' => $e]);
            } catch (IscsiTargetException $e) {
                $this->logger->info('ASR0002 Cannot load iscsi asset', ['assetKey' => $keyName, 'exception' => $e]);
            } catch (Throwable $e) {
                $this->logger->error('ASR0003 Unable to unserialize', ['assetKey' => $keyName, 'exception' => $e]);
            }
        }

        $this->logger->removeFromGlobalContext(DeviceLogger::CONTEXT_ASSET);

        return $assets;
    }

    /**
     * Returns a list of all available assets filtered by replicated, archived, and type.
     *
     * @param bool $getReplicated Whether replicated assets will be included
     * @param bool $getArchived Whether archived assets will be included
     * @param string|null $type An AssetType constant. Only get assets that match this type.
     * @return string[] List of asset key names.
     */
    public function getAllNames(bool $getReplicated, bool $getArchived, string $type = null)
    {
        $allKeyNames = $this->agentConfigFactory->getAllKeyNames();
        $filteredKeyNames = [];

        foreach ($allKeyNames as $keyName) {
            if ($this->checkType($keyName, $getReplicated, $getArchived, $type)) {
                $filteredKeyNames[] = $keyName;
            }
        }

        return $filteredKeyNames;
    }


    /**
     * Checks if we want to unserialize this asset.
     *
     * This checks that the asset with $keyName matches the $wantedType, and optionally excludes
     * assets that are replicated or archived. This requires unserializing agentInfo to check the type,
     * which is costly. However, it is much more efficient to do this filtering now instead of
     * after the entire asset has been unserialized.
     *
     * @param string $keyName
     * @param bool $includeReplicated Whether to return true for replicated assets
     * @param bool $includeArchived Whether to return true for archived assets
     * @param string|null $wantedType Returns true if the asset is the same type
     * @return bool True if the asset is of the desired type, false otherwise
     */
    private function checkType(string $keyName, bool $includeReplicated, bool $includeArchived, string $wantedType = null): bool
    {
        $agentConfig = $this->agentConfigFactory->create($keyName);

        if (!$includeArchived && $agentConfig->isArchived()) {
            return false;
        }

        if (!$includeReplicated && $agentConfig->isReplicated()) {
            return false;
        }

        $agentInfo = @unserialize($agentConfig->getRaw($this->mainExtension), ['allowed_classes' => false]);

        return !empty($agentInfo) && ($wantedType === null || AssetType::isType($wantedType, $agentInfo));
    }

    /**
     * Pre-Save logic
     * @param $keyName
     * @param $fileArray
     */
    protected function preSave($keyName, $fileArray)
    {
        // nothing
    }

    /**
     * Read the config files for all of the extensions managed by this
     * repository and return an array in the form extension/file-content.
     *
     * @param string $keyName the key name of the asset
     * @return array Extension-to-file-content array
     */
    protected function readConfigFiles($keyName)
    {
        $contents = ['keyName' => $keyName];
        $agentConfig = $this->agentConfigFactory->create($keyName);

        foreach ($this->extensions as $extension) {
            $contents[$extension] = $agentConfig->getRaw($extension, null);
        }

        foreach ($this->flagExtensions as $flagExtension) {
            $contents[$flagExtension] = $agentConfig->has($flagExtension);
        }

        return $contents;
    }

    /**
     * Merges PHP-serialized file contents from the existing keyfile with
     * the given contents.
     *
     * @param string $keyName Asset key name
     * @param string $extension Extension to of filename to load old file from
     * @param string $contents PHP-serialized new contents
     * @return string Serialized original contents overwritten with new contents
     */
    protected function mergeSerializedArrays($keyName, $extension, $contents)
    {
        $agentConfig = $this->agentConfigFactory->create($keyName);

        if ($agentConfig->has($extension)) {
            $newArr = unserialize($contents, ['allowed_classes' => false]);
            $existingArr = unserialize($agentConfig->getRaw($extension), ['allowed_classes' => false]);
            $mergedArr = array_replace_recursive($existingArr, $newArr);
            $contents = serialize($mergedArr);
        }

        return $contents;
    }

    /**
     * Merges JSON-encoded file contents from the existing keyfile with
     * the given contents.
     *
     * @param string $keyName Asset key name
     * @param string $extension Extension to of filename to load old file from
     * @param string $contents JSON-encoded new contents
     * @return string JSON-encoded original contents overwritten with new contents
     */
    protected function mergeJsonArrays($keyName, $extension, $contents)
    {
        $agentConfig = $this->agentConfigFactory->create($keyName);

        if ($agentConfig->has($extension)) {
            $newArr = json_decode($contents, true);
            $existingArr = json_decode($agentConfig->getRaw($extension), true);
            $mergedArr = array_replace_recursive($existingArr, $newArr);
            $contents = json_encode($mergedArr);
        }

        return $contents;
    }

    /**
     * Apply keyfile-specific patches to ensure that unknown values in a keyfiles
     * are not thrown away, and that the format is exactly the same as before.
     *
     * @param string $keyName
     * @param string $extension
     * @param string $contents
     * @return string
     */
    protected function mergeExistingFile($keyName, $extension, $contents)
    {
        if ($extension === 'agentInfo' || $extension === 'emails') {
            $contents = $this->mergeSerializedArrays($keyName, $extension, $contents);
        } elseif ($extension === 'offsiteControl') {
            $contents = $this->mergeJsonArrays($keyName, $extension, $contents);
        }
        return $contents;
    }
}
