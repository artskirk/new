<?php

namespace Datto\Utility\Block;

use Datto\Common\Resource\ProcessFactory;
use Datto\Util\StringUtil;

/**
 * Utility to create, destroy, and list device mapper devices.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Dmsetup
{
    private ProcessFactory $processFactory;

    /**
     * @param ProcessFactory $processFactory
     */
    public function __construct(
        ProcessFactory $processFactory
    ) {
        $this->processFactory = $processFactory;
    }

    /**
     * Create a device mapper
     *
     * @param string $name Name of the device mapper
     * @param string $tableFile dmsetup table file
     * @param bool $readonly
     */
    public function create(string $name, string $tableFile, bool $readonly)
    {
        $command = ['dmsetup', 'create'];
        if ($readonly) {
            $command[] = '--readonly';
        }
        $command[] = $name;
        $command[] = $tableFile;
        $this->processFactory->get($command)
            ->mustRun();
    }

    /**
     * Destroy a device mapper
     *
     * @param string $deviceMapperPath
     */
    public function destroy(string $deviceMapperPath)
    {
        $this->processFactory->get(['dmsetup', 'remove', '--retry', $deviceMapperPath])
            ->mustRun();
    }

    /**
     * Get list of all device mappers
     *
     * @return array [
     *     [0] => [
     *         'displayName' => '5c55cf66f72211e880c1806e6f6e6963-crypt-70584b01',
     *         'deviceName' => 'dm-0'
     *     ],
     *     ...
     * ]
     */
    public function getAll(): array
    {
        $process = $this->processFactory->get(['dmsetup', 'ls', '-o', 'blkdevname']);
        $process->run();

        $deviceMappers = [];
        if ($process->isSuccessful()) {
            // Example output:
            //      5c55cf66f72211e880c1806e6f6e6963-crypt-70584b01         (dm-0)
            //      8c60d9c1-df68-4d6e-b1ab-38b6471d4be6-crypt-8553cf0c     (dm-1)
            //      0b5522a2-8f4d-4a73-8e53-124dc5f66751-crypt-0bd988c7     (dm-2)

            $lines = StringUtil::splitByNewline(trim($process->getOutput()));
            $lines = preg_grep('/^No devices found/', $lines, PREG_GREP_INVERT);
            foreach ($lines as $line) {
                $matches = [];
                if (preg_match('/^(\S+).+\((\S+)\)$/', $line, $matches)) {
                    $displayName = $matches[1];
                    $deviceName = $matches[2];
                    $deviceMappers[] = ['displayName' => $displayName, 'deviceName' => $deviceName];
                }
            }
        }
        return $deviceMappers;
    }

    /**
     * Check if a device mapper exists based on its own.
     *
     * @param string $name
     * @return bool
     */
    public function exists(string $name): bool
    {
        $process = $this->processFactory->get([
            'dmsetup',
            'info',
            $name
        ]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Retrieves a list of device display names for a dmsetup target
     *
     * @param string $targetName eg 'crypt'
     * @return string[] eg ['999abc99-0000-0000-0000-100000-crypt-999abc9', ...]
     */
    public function getDevicesForTarget(string $targetName): array
    {
        $process = $this->processFactory->get(
            ['dmsetup', 'ls', '--target', $targetName]
        );
        $process->run();

        if ($process->isSuccessful()) {
            $lines = StringUtil::splitByNewline(trim($process->getOutput()));
            $lines = preg_grep('/^No devices found/', $lines, PREG_GREP_INVERT);

            return array_map(function ($row) {
                //Return the first token, the filename
                return StringUtil::splitByWhitespace($row)[0];
            }, $lines);
        }

        return [];
    }

    /**
     * Retrieve the path to a loop device for a device display name, or throws an exception
     *
     * @param string $deviceName eg '999abc99-0000-0000-0000-100000-crypt-999abc9'
     * @return string eg '/dev/loop5'
     */
    public function getLoopPathForDevice(string $deviceName): string
    {
        $process = $this->processFactory->get(['dmsetup', 'deps', $deviceName]);
        $process->run();

        if ($process->isSuccessful()) {
            //Example output: '1 dependencies: (2, 3)'
            $numbers = StringUtil::extractIntegers($process->getOutput());
            return '/dev/loop' . $numbers[2];
        }

        throw new \Exception('Loop path for device could not be found: ' . $deviceName);
    }
}
