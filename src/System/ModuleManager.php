<?php

namespace Datto\System;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Add/Remove/Configure linux kernel modules
 *
 * Migrated from older code written by jason
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class ModuleManager
{
    const MODULE_FILE = '/etc/modules';
    const MODPROBE_DIR = '/etc/modprobe.d';

    /** @var Filesystem */
    private $filesystem;

    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory = null, Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->processFactory = $processFactory ?: new ProcessFactory();
    }

    /**
     * Determines if the specified module exists in the modules file
     *
     * @param string $module
     * @return bool  true if the module is listed in the modules file
     */
    public function moduleExists($module)
    {
        $contents = $this->getModuleContents();
        if ($contents === null) {
            return false;
        }
        foreach (explode("\n", $contents) as $line) {
            if (preg_match("/^$module($|\s)/", $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $module
     */
    public function probe(string $module)
    {
        $process = $this->processFactory->get(['modprobe', $module]);

        $process->mustRun();
    }

    /**
     * Adds a new module to the modules file. Performs an update if the module already exists.
     *
     * @param string $module
     * @param array $options|null  An array of key/value pairs
     * @param bool $loadImmediately|false Runs a modprobe command to load it
     * @return bool true on success
     */
    public function addModule($module, $options = null, $loadImmediately = false)
    {
        if ($options) {
            $this->saveOptions($module, $options);
        }
        if (!$this->moduleExists($module)) {
            $result = $this->filesystem->filePutContents(self::MODULE_FILE, "$module\n", FILE_APPEND) !== false;
            if ($result && $loadImmediately) {
                $this->probe($module);
            }
        }
        return true;
    }

    /**
     * Removes the specified module from the modules file.
     *
     * @param string $module
     * @param bool $immediately|true Will attemt to run rmmod on the module
     * @param bool $purge|false Remove the driver options file if present
     * @return bool true on success
     */
    public function removeModule($module, $immediately = true, $purge = false)
    {
        if (!$this->moduleExists($module)) {
            return true;
        }

        $output = array();
        $contents = $this->getModuleContents();
        foreach (explode("\n", $contents) as $line) {
            if (!preg_match("/^$module($|\s)/", $line)) {
                $output[] = $line;
            }
        }

        $result = $this->filesystem->filePutContents(self::MODULE_FILE, implode("\n", $output)) !== false;
        if ($result && $immediately) {
            $process = $this->processFactory->get(['rmmod', $module]);
            $process->run();  // only attempt. Drivers in use will fail to unload.
        }

        if ($purge) {
            $this->deleteOptions($module);
        }
        return true;
    }

    /**
     * Writes an options configuration file to /etc/modules.d for the given driver
     *
     * @param string $module
     * @param array $options An array of key/value pairs
     */
    public function saveOptions($module, $options)
    {
        $formatted_options = "options $module";
        foreach ($options as $key => $value) {
            $formatted_options .= sprintf(' %s=%s', $key, $value);
        }
        $formatted_options .= "\n";
        $this->filesystem->filePutContents(self::MODPROBE_DIR . '/' . $module . '.conf', $formatted_options);
    }

    /**
     * Removes the options configuration file from /etc/modules.d
     *
     * @param $module
     */
    public function deleteOptions($module)
    {
        $optionsFile = sprintf('%s/%s.conf', self::MODPROBE_DIR, $module);
        if ($this->filesystem->exists($optionsFile)) {
            $this->filesystem->unlink($optionsFile);
        }
    }

    /**
     * Gets the content of the modules file
     *
     * @return null|string
     */
    private function getModuleContents()
    {
        if ($this->filesystem->exists(self::MODULE_FILE)) {
            return $this->filesystem->fileGetContents(self::MODULE_FILE);
        }
        return null;
    }
}
