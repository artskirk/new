<?php

namespace Datto\Asset\Agent\Windows;

use Datto\Finder\ResettableFinder;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Symfony\Component\Finder\SplFileInfo;
use Exception;

/**
 * Locates the Windows registry hive files in a mounted OS volume.
 *
 * Note: this is based on code originally in the class
 *       src/System/Inspection/Injector/Windows.php
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class WindowsRegistryLocator
{
    /** @var Filesystem */
    private $filesystem;

    /** @var ResettableFinder */
    private $finder;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * Init constructor.
     *
     * @param Filesystem $filesystem
     * @param ResettableFinder $finder
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        Filesystem $filesystem,
        ResettableFinder $finder,
        DeviceLoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->finder = $finder;
    }

    /**
     * @param string $mountPoint Linux filesystem path where the Windows OS
     *     filesystem is mounted.
     * @return string Linux filesystem path to the system registry hive file
     *     in the mounted Windows OS volume.
     */
    public function getSystemHivePath(string $mountPoint): string
    {
        return $this->locateRegistryHive($mountPoint, 'system');
    }

    /**
     * @param string $mountPoint Linux filesystem path where the Windows OS
     *     filesystem is mounted.
     * @return string Linux filesystem path to the software registry hive file
     *     in the mounted Windows OS volume.
     */
    public function getSoftwareHivePath(string $mountPoint): string
    {
        return $this->locateRegistryHive($mountPoint, 'software');
    }

    /**
     * Locate a registry hive.
     *
     * @param string $mountPoint Linux filesystem path where the Windows OS
     *     filesystem is mounted.
     * @param string $type Can be one of either 'system' or 'software'.
     * @return string The Linux filesystem path to the system registry hive
     *     in the mounted Windows OS volume.
     */
    private function locateRegistryHive(string $mountPoint, string $type): string
    {
        $this->logger->debug('WRL0001 Locating system hive');

        $system32Path = $this->findFilesystemChild(
            'system32',
            $this->getWindowsPath($mountPoint)
        );
        if ($system32Path === null) {
            throw new Exception('Failed to locate system32.');
        }

        $configPath = $this->findFilesystemChild('config', $system32Path);
        if ($configPath === null) {
            throw new Exception('Failed to locate config.');
        }

        $hivePath = $this->findFilesystemChild($type, $configPath, 'files');
        if ($hivePath === null) {
            throw new Exception("Failed to locate $type hive.");
        }

        $this->logger->debug("WRL0002 Found $type hive: $hivePath");

        return $hivePath;
    }

    /**
     * Get the Linux filesystem path to the mounted Windows directory.
     *
     * @param string $mountPoint Linux filesystem path where the Windows OS
     *     filesystem is mounted.
     * @return string
     */
    private function getWindowsPath(string $mountPoint): string
    {
        if (!$this->filesystem->exists($mountPoint)) {
            throw new Exception("Mount point does not exist: $mountPoint");
        }

        if (!$this->filesystem->isDir($mountPoint)) {
            throw new Exception("Mount point is not a directory: $mountPoint");
        }

        $this->finder->reset();
        $this->finder
            ->directories()
            ->in($mountPoint)
            ->depth('== 0')
            ->name('/^(windows|winnt)$/i');

        /** @var SplFileInfo[] $finderArray */
        $finderArray = iterator_to_array($this->finder, false);

        if (count($finderArray) === 1) {
            // If there's only one match, use it.
            $windowsPath = $finderArray[0]->getRealPath();
        } else {
            // If there are multiple matches, find the first one that actually contains a Windows installation.
            foreach ($finderArray as $fileInfo) {
                $path = $fileInfo->getRealPath();
                if ($this->isWindowsDirectory($path)) {
                    $windowsPath = $path;
                    break;
                }
            }

            if (empty($windowsPath)) {
                throw new Exception("Failed to locate Windows directory.");
            }
        }

        return $windowsPath;
    }

    /**
     * Verify that a directory actually contains a Windows installation.
     *
     * Checks the provided directory for the following items:
     *  * system32
     *  * system32/cmd.exe
     *  * system32/msiexec.exe
     *  * system32/notepad.exe
     *  * system32/taskmgr.exe
     *  * system32/regedt32.exe
     *
     * @see https://msdn.microsoft.com/en-us/library/dd184075.aspx
     *   Lists executables that exist in all versions of Windows.
     *
     * @param string $path The path to the suspected Windows directory.
     * @return bool TRUE if the directory contains the items listed above.
     */
    private function isWindowsDirectory(string $path): bool
    {
        $foundWindows = false;

        // Locate System32.
        $this->finder->reset();
        $this->finder
            ->in($path)
            ->depth('== 0')
            ->name('/^system32$/i');

        /** @var SplFileInfo[] $system32Array */
        $system32Array = iterator_to_array($this->finder, false);

        if (count($system32Array)) {
            $system32Path = $system32Array[0]->getRealPath();

            // Locate executables.
            $expectedBinaries = array('cmd', 'msiexec', 'notepad', 'taskmgr', 'regedt32');
            $nameRegex = '/^(' . implode('|', $expectedBinaries) . ').exe$/i';
            $this->finder->reset();
            $this->finder
                ->in($system32Path)
                ->depth('== 0')
                ->name($nameRegex);

            // If all the expected binaries were found, this is a Windows directory.
            $foundWindows = $this->finder->count() === count($expectedBinaries);
        }

        return $foundWindows;
    }

    /**
     * Case insensitively locate a filesystem child.
     *
     * @param string $searchName The case-insensitive name of the entity to locate.
     * @param string $parent The directory to search within.
     * @param string $type The type of entity to locate: either 'directories' or 'files'
     * @return null|string
     */
    private function findFilesystemChild(string $searchName, string $parent, string $type = 'directories')
    {
        $foundPath = null;

        $this->finder->reset();
        $this->finder
            ->$type()
            ->in($parent)
            ->depth('== 0')
            ->name('/^' . $searchName . '$/i');

        /** @var SplFileInfo[] $finderArray */
        $finderArray = iterator_to_array($this->finder, false);

        if (count($finderArray) === 1) {
            $foundPath = $finderArray[0]->getRealPath();
        }

        return $foundPath;
    }
}
