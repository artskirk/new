<?php

namespace Datto\Utility\Block;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use RuntimeException;

/**
 * Utility to create, destroy, and list loop devices.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Losetup
{
    private const LOSETUP = 'losetup';
    private const OPTION_LIST = '--list';
    private const OPTION_JSON = '--json';
    private const OPTION_SHOW = '--show';
    private const OPTION_FIND = '--find';
    private const OPTION_DETACH = '--detach';
    private const OPTION_ASSOCIATED = '--associated';
    private const OPTION_NO_HEADINGS = '--noheadings';
    private const OPTION_PARTITION_SCAN = '--partscan';
    private const OPTION_READ_ONLY = '--read-only';
    private const OPTION_OUTPUT = '--output';
    private const OPTION_OUTPUT_BACKFILE = 'BACK-FILE';
    private const OPTION_OUTPUT_AUTOCLEAR = 'AUTOCLEAR';

    /**
     * Available options for loop creation
     * To use multiple options bitwise OR (|) them together.
     */
    public const LOOP_CREATE_NONE = 0x0;      // 0000
    public const LOOP_CREATE_READ_ONLY = 0x1; // 0001
    public const LOOP_CREATE_PART_SCAN = 0x2; // 0010

    private ProcessFactory $processFactory;
    private Filesystem $filesystem;

    /**
     * @param ProcessFactory $processFactory
     * @param Filesystem $filesystem
     */
    public function __construct(
        ProcessFactory $processFactory,
        Filesystem $filesystem
    ) {
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
    }

    /**
     * Create a loop device backed by the specified target.
     * The options are declared bitmask-style, for example:
     *    (..., Losetup::LOOP_CREATE_READ_ONLY | Losetup::LOOP_CREATE_PART_SCAN)
     * Will result in the created loop being partprobe'd and read only.
     *
     * @param string $backingFile
     * @param int $options
     * @return string
     */
    public function create(string $backingFile, int $options): string
    {
        $backingFile = $this->filesystem->realpath($backingFile);
        if ($backingFile === false) {
            throw new RuntimeException('The given target file "' . $backingFile . '" does not exist.');
        }

        $command = [self::LOSETUP, self::OPTION_SHOW, self::OPTION_FIND];

        if ($options & self::LOOP_CREATE_PART_SCAN) {
            $command[] = self::OPTION_PARTITION_SCAN;
        }

        if ($options & self::LOOP_CREATE_READ_ONLY) {
            $command[] = self::OPTION_READ_ONLY;
        }

        $command[] = $backingFile;

        $process = $this->processFactory->get($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Loop creation failed: ' . $process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    /**
     * Delete loop device.
     *
     * @param string $name Path to loop block device
     */
    public function destroy(string $name)
    {
        $process = $this->processFactory->get([self::LOSETUP, self::OPTION_DETACH, $name]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to remove loop device ' .
                $name . ': ' . $process->getErrorOutput()
            );
        }
    }

    /**
     * Get a list of all existing loop devices.
     *
     * @return string[]
     */
    public function getAllLoops(): array
    {
        $command = [self::LOSETUP, self::OPTION_LIST, self::OPTION_JSON];
        $process = $this->processFactory->get($command);
        $process->run();

        $loops = [];
        if ($process->isSuccessful()) {
            $output = $process->getOutput();
            $loops = $this->getLoopsFromOutput($output);
        }
        return $loops;
    }

    /**
     * Get map of all existing loops with the given backing file.
     *
     * @param string $backingFile
     * @return string[] - an associative array of loop => backing file string pairs
     */
    public function getLoopsByBackingFile(string $backingFile): array
    {
        $backingFileExists = $this->filesystem->realpath($backingFile);
        if ($backingFileExists === false) {
            throw new RuntimeException('The given target file "' . $backingFile . '" does not exist.');
        }

        $command = [self::LOSETUP, self::OPTION_LIST, self::OPTION_JSON, self::OPTION_ASSOCIATED, $backingFile];
        $process = $this->processFactory->get($command);
        $process->run();

        $loops = [];
        if ($process->isSuccessful()) {
            $output = $process->getOutput();
            $loops = $this->getLoopsFromOutput($output);
        }
        return $loops;
    }

    /**
     * Determine if a loop with the given name exists.
     *
     * @param string $name
     * @return bool
     */
    public function exists(string $name): bool
    {
        $exists = false;
        $loops = $this->getAllLoops();
        foreach ($loops as $loop => $backingFile) {
            if ($name === $loop) {
                $exists = true;
                break;
            }
        }
        return $exists;
    }

    /**
     * Get the backing file for the given loop
     *
     * @param string $name Path to loop block device
     * @return string Path to the backing file
     */
    public function getBackingFile(string $name): string
    {
        $command = [
            self::LOSETUP,
            self::OPTION_LIST,
            self::OPTION_OUTPUT,
            self::OPTION_OUTPUT_BACKFILE,
            self::OPTION_NO_HEADINGS,
            $name
        ];
        $process = $this->processFactory->get($command);
        $process->run();

        $backingFile = '';
        if ($process->isSuccessful()) {
            $backingFile = trim($process->getOutput());
        }
        return $backingFile;
    }

    /**
     * Translate losetup output into loop objects
     *
     * @param string $processOutput
     * @return string[]
     */
    private function getLoopsFromOutput(string $processOutput): array
    {
        $decodedOutput = json_decode($processOutput, true);
        $loopDevices = $decodedOutput['loopdevices'] ?? [];

        $loops = [];
        foreach ($loopDevices as $loopDevice) {
            $name = $loopDevice['name'] ?? '';
            $backingFile = $loopDevice['back-file'] ?? '';
            $loops[$name] = $backingFile;
        }
        return $loops;
    }

    /**
     * Returns true if the loop has been deleted but remains in use, indicating a hung or leaked loop.
     *
     * @param string $loopPath
     */
    public function isHanging(string $loopPath): bool
    {
        $command = [
            self::LOSETUP,
            $loopPath,
            self::OPTION_NO_HEADINGS,
            self::OPTION_OUTPUT,
            self::OPTION_OUTPUT_AUTOCLEAR
        ];
        $process = $this->processFactory->get($command);
        $process->run();

        if ($process->isSuccessful()) {
            $output = trim($process->getOutput());
            return $output == '1';
        }

        return false;
    }
}
