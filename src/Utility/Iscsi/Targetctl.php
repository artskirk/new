<?php

namespace Datto\Utility\Iscsi;

use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Utility\File\LockInfo;
use RuntimeException;

/**
 * Save and restore LIO kernel target configuration.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class Targetctl
{
    const SUDO = 'sudo';
    const TARGETCTL_COMMAND = 'targetctl';
    const TARGETCTL_SAVE = 'save';
    const TARGETCTL_RESTORE = 'restore';
    const TARGETCTL_CLEAR = 'clear';

    /** @var ProcessFactory */
    private $processFactory;

    /** @var Lock */
    private $configFSLock;

    /**
     * @param ProcessFactory $processFactory
     * @param LockFactory $lockFactory
     */
    public function __construct(
        ProcessFactory $processFactory,
        LockFactory $lockFactory
    ) {
        $this->processFactory = $processFactory;
        $this->configFSLock = $lockFactory->getProcessScopedLock(LockInfo::CONFIGFS_LOCK_PATH);
    }

    /**
     * Save the LIO configuration to the given file path
     *
     * @param string $saveFilePath
     */
    public function saveConfiguration(string $saveFilePath)
    {
        // This test is just to make sure this function is not misused
        if (!$this->configFSLock->isLocked()) {
            throw new RuntimeException('This function requires a lock');
        }

        $process = $this->processFactory->get([
            self::SUDO,
            self::TARGETCTL_COMMAND,
            self::TARGETCTL_SAVE,
            $saveFilePath
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to save temporary LIO configuration to ' . $saveFilePath .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Clear the LIO configuration
     */
    public function clearConfiguration()
    {
        // This test is just to make sure this function is not misused
        if (!$this->configFSLock->isLocked()) {
            throw new RuntimeException('This function requires a lock');
        }

        $process = $this->processFactory->get([
            self::SUDO,
            self::TARGETCTL_COMMAND,
            self::TARGETCTL_CLEAR
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to clear LIO configuration: ' .
                ($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }
}
