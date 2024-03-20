<?php

namespace Datto\Samba;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Represents a samba configuration file. Advisory (not atomic) file locks are
 * used in order to manipulate the files so that running daemons / other processes
 * can continue to read.
 *
 * @author Evan Buther <evan.buther@dattobackup.com>
 */
class SambaFile
{
    /**
     * @var string  The full file path of the configuration file
     */
    private $file = '';

    /**
     * @var null  Stored file handler (to be used)
     */
    private $handle = null;

    /**
     * @var array  The file path(s) to include within this file
     */
    private $includes = [];

    /**
     * @var SambaSection[]  Section objects to be included in this file
     */
    private $sections = [];

    /** @var Filesystem */
    private $filesystem;

    /** @var UserService */
    private $userService;

    /**
     * Instantiates the file and reads if the file exists
     *
     * @param string $file The full path of the configuration file
     * @param Filesystem|null $filesystem
     * @param UserService|null $userService
     */
    public function __construct(
        string $file,
        Filesystem $filesystem = null,
        UserService $userService = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->userService = $userService ?: new UserService();
        $this->file = $file;

        if (!$this->filesystem->isFile($file)) {
            $fileDirectory = dirname($file);

            if (!$this->filesystem->isDir($fileDirectory)) {
                throw new Exception('The directory \'' . $fileDirectory . '\' does not exist. Cannot create SambaFile.');
            }
        } else {
            $this->parseFile();
        }
    }

    /**
     * Opens / locks the file and stores the handler
     *
     * @param bool $exclusive Whether the lock should be exclusive or not
     */
    public function openAndLock(bool $exclusive = false)
    {
        if (!$this->filesystem->exists($this->file)) {
            throw new Exception('The file ' . $this->file . ' does not exist.');
        }

        if (!$exclusive) {
            $this->handle = $this->filesystem->open($this->file, 'r');
            @$this->filesystem->lock($this->handle, LOCK_SH);
        } else {
            $this->handle = $this->filesystem->open($this->file, 'r+');
            @$this->filesystem->lock($this->handle, LOCK_EX);
        }
    }

    /**
     * Unlocks and close the file (regardless of exclusivity)
     */
    public function unlockAndCloseFile()
    {
        if ($this->filesystem->lock($this->handle, LOCK_UN)) {
            $this->filesystem->close($this->handle);
        }
    }

    /**
     * Public accessor to reread/reload the stored file
     */
    public function reload()
    {
        if ($this->filesystem->isFile($this->file)) {
            $this->parseFile();
        }
    }

    /**
     * Returns the includes associated with this configuration file
     *
     * @return string[]
     */
    public function getIncludes(): array
    {
        return $this->includes;
    }

    /**
     * Whether or not this file is valid (checks each include/section for validity)
     *
     * @param bool $forWrite Whether or not the file is about to be written
     * @return bool
     */
    public function isValid(bool $forWrite = false): bool
    {
        if (!empty($this->includes)) {
            if (!$forWrite) {
                return true;
            } else {
                foreach ($this->includes as $includeKey => $include) {
                    if (!$this->filesystem->exists($include)) {
                        unset($this->includes[$includeKey]);
                    }
                }

                if (!empty($this->includes)) {
                    return true;
                }
            }
        }

        foreach ($this->sections as $section) {
            if ($section->isValid()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes the actual file
     */
    public function delete()
    {
        if ($this->filesystem->exists($this->file)) {
            $this->filesystem->unlink($this->file);
        }
    }

    /**
     * Creates a cache file, writes to that, then the cache file will move to the actual file location
     *
     * @param bool $debugOutput Creates cache file(s) but does not move them to the proper location if set to TRUE
     */
    public function write(bool $debugOutput = false)
    {
        if (!$this->isValid(true)) {
            $this->delete();
            return;
        }

        // Create the cache file that will replace the config file
        $cacheHandle = $this->filesystem->open($this->file . '.cache', 'w+');

        $this->filesystem->lock($cacheHandle, LOCK_EX);

        $fileOutput = '';
        foreach ($this->sections as $section) {
            if ($section->isComment($section->getName())) {
                // Prepend the output with the comment section
                $fileOutput = $section->confOutput() . $fileOutput;
            } else {
                $fileOutput .= $section->confOutput();
            }
        }

        $this->filesystem->write($cacheHandle, $fileOutput);

        foreach ($this->includes as $includeFile) {
            $this->filesystem->write($cacheHandle, "\tinclude = " . $includeFile . "\n");
        }

        $this->filesystem->lock($cacheHandle, LOCK_UN);
        $this->filesystem->close($cacheHandle);

        if (!$debugOutput) {
            $this->filesystem->rename($this->file . '.cache', $this->file);
        }
    }

    /**
     * Returns the full path of the configuration file
     *
     * @return string  The full path of the configuration file
     */
    public function getFilePath(): string
    {
        return $this->file;
    }

    /**
     * Returns an array of sections / shares associated with this file
     *
     * @param bool $sharesOnly Whether or not to return only SambaShare objects
     * @return SambaSection[]  Array of section/share objects
     */
    public function getSections(bool $sharesOnly = false): array
    {
        $shareObjects = [];
        foreach ($this->sections as $section) {
            if (!$sharesOnly || $section instanceof SambaShare) {
                $shareObjects[] = $section;
            }
        }

        return $shareObjects;
    }

    /**
     * Associates an existing section/share object to the file
     *
     * @param $newSection  SambaSection or SambaShare object to be added
     */
    public function addSection(SambaSection $newSection)
    {
        foreach ($this->sections as $section) {
            if ($section->getName() == $newSection->getName()) {
                throw new Exception('Section \'' . $section->getName() . '\' already exists in \'' . $this->file . '\'.');
            }
        }
        $this->sections[] = $newSection;
    }

    /**
     * Associates a file path to be included in this file
     *
     * @param SambaFile $newInclude The new include path
     */
    public function addInclude(SambaFile $newInclude)
    {
        if (!in_array($newInclude->getFilePath(), $this->includes)) {
            $this->includes[] = $newInclude->getFilePath();
        }
    }

    /**
     * Resets the sections previously loaded by last config file read
     *
     * Useful for clearing sections deleted by racing SambaManagers
     */
    public function clearLoadedSections()
    {
        $this->sections = [];
    }

    /**
     * Reads the configuration file while instantiating and assigning necessary properties
     */
    private function parseFile()
    {
        $createdHandle = false;

        if ($this->handle === null || !$this->filesystem->isFileResource($this->handle)) {
            $this->openAndLock();
            $createdHandle = true;
        }

        $fileAsString = $this->filesystem->exists($this->file) ? $this->filesystem->fileGetContents($this->file) : '';

        // Split the file by section
        $sectionStrings = preg_split("/\n(?=\[)/", $fileAsString);

        // These are the properties that can override existing section properties
        $overrideProperties = ['include', 'config file', 'copy'];

        if (count($sectionStrings) == 1 && strpos($sectionStrings[0], '[') === false) {
            // This file does not contain any sections
            $sectionLines = explode("\n", $sectionStrings[0]);

            foreach ($sectionLines as $sectionLine) {
                $line = trim($sectionLine);

                if (preg_match('/^([A-z0-9 :_-]+?)[ \t]*=[\t ]*(.*?)$/', $line, $match)) {
                    // Key / Value pair match
                    $propertyKey = $match[1];
                    $propertyValue = $match[2];

                    if (strtolower($propertyKey) == 'include' && !in_array($propertyValue, $this->includes)) {
                        array_push($this->includes, $propertyValue);
                    } elseif (strtolower($propertyKey) == 'config file') {
                        throw new Exception('Config files are forbidden as they override global/secure settings.');
                    }
                }
            }
        } else {
            // The file contains sections
            foreach ($sectionStrings as $sectionString) {
                // We can get an empty "section" if there is whitespace before the first section.
                if (!trim($sectionString)) {
                    continue;
                }

                // Grab the section headline (via. split, assumed to be the first line)
                $sectionHeadline = trim(strtok($sectionString, "\n"));
                $sectionName = str_replace(['[', ']'], '', $sectionHeadline);

                if (!isset($this->sections[$sectionName])) {
                    $section = SambaSection::create($sectionName, $this->userService, $this->filesystem);
                    $this->sections[$sectionName] = $section;
                }

                $this->sections[$sectionName]->load($sectionName, trim($sectionString));

                foreach ($overrideProperties as $overrideProperty) {
                    $overrideValue = $this->sections[$sectionName]->getProperty($overrideProperty);

                    if ($overrideProperty == 'copy' && in_array($overrideValue, array_keys($this->sections))) {
                        // Copy overrides from existing section
                        // Append non-existant properties to THIS share
                    } elseif ($this->sections[$sectionName]->isPropertySet($overrideProperty)) {
                        $overrideValues = is_array($overrideValue) ? $overrideValue : [$overrideValue];

                        foreach ($overrideValues as $singleOverrideValue) {
                            if ($this->filesystem->isFile($singleOverrideValue) && $singleOverrideValue != $this->file) {
                                switch ($overrideProperty) {
                                    case 'include':
                                        if (!in_array($singleOverrideValue, $this->includes)) {
                                            array_push($this->includes, $singleOverrideValue);
                                        }
                                        break;

                                    case 'config file':
                                        throw new Exception('Config files are forbidden as they override global/secure settings.');
                                }
                            }
                        }

                        $this->sections[$sectionName]->removeProperty('include');
                    }
                }
            }
        }

        if ($createdHandle) {
            $this->unlockAndCloseFile();
        }
    }
}
