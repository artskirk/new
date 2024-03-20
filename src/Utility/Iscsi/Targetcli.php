<?php

namespace Datto\Utility\Iscsi;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Utility\File\LockInfo;
use RuntimeException;

/**
 * Utility to create and destroy LIO iSCSI targets, backstores, and LUNs.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Mark Blakley <mblakley@datto.com>
 */
class Targetcli
{
    const SUDO = 'sudo';
    const TARGETCLI_COMMAND = 'targetcli';
    const TARGETCLI_CREATE = 'create';
    const TARGETCLI_DELETE = 'delete';

    const ACCESS_TYPE_BLOCK = 'block';
    const ACCESS_TYPE_FILEIO = 'fileio';

    const ISCSI_PATH = '/iscsi';
    const BACKSTORES_PATH = '/backstores';

    /** @var ProcessFactory */
    private $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var Lock */
    private $configFSLock;

    /**
     * @param ProcessFactory $processFactory
     * @param Filesystem $filesystem
     * @param LockFactory $lockFactory
     */
    public function __construct(
        ProcessFactory $processFactory,
        Filesystem $filesystem,
        LockFactory $lockFactory
    ) {
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
        $this->configFSLock = $lockFactory->getProcessScopedLock(LockInfo::CONFIGFS_LOCK_PATH);
    }

    /**
     * Create a new iSCSI target
     *
     * @param string $target Target's iSCSI Qualified Name (IQN)
     */
    public function createTarget(string $target)
    {
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get([
                self::SUDO,
                self::TARGETCLI_COMMAND,
                self::ISCSI_PATH,
                self::TARGETCLI_CREATE,
                $target
            ]);
            $process->run();
        } finally {
            $this->configFSLock->unlock();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to create iSCSI target ' . $target .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Delete an iSCSI target
     *
     * @param string $target Target's iSCSI Qualified Name (IQN)
     */
    public function deleteTarget(string $target)
    {
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get([
                self::SUDO,
                self::TARGETCLI_COMMAND,
                self::ISCSI_PATH,
                self::TARGETCLI_DELETE,
                $target
            ]);
            $process->run();
        } finally {
            $this->configFSLock->unlock();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to delete iSCSI target ' . $target .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Create the backstore
     *
     * @param string $target Target's iSCSI Qualified Name (IQN)
     * @param string $backstoreName Backstore name
     * @param string $path Path that the backstore should be associated with
     * @param bool $readOnly Set to TRUE to make the backstore (or entire TPG for fileio backstores) read-only
     * @param bool $writeBack Set to TRUE to enable write-back caching; uses write-through by default
     * @param string|null $wwn World Wide Name for the LUN (optional); This is a unique identifier used in storage technologies.
     *  VMware displays this in the volume's durableName property
     * @param array $attributes Attributes to apply to the backstore
     * @return string Backstore path
     */
    public function createBackstore(
        string $target,
        string $backstoreName,
        string $path,
        bool $readOnly,
        bool $writeBack,
        string $wwn = null,
        array $attributes = []
    ): string {
        $accessType = $this->getAccessType($path);

        $command = [
            self::SUDO,
            self::TARGETCLI_COMMAND,
            self::BACKSTORES_PATH . '/' . $accessType,
            self::TARGETCLI_CREATE,
            $backstoreName,
            $path
        ];

        switch ($accessType) {
            case self::ACCESS_TYPE_BLOCK:
                $command[] = $readOnly ? 'true' : 'false';
                break;
            case self::ACCESS_TYPE_FILEIO:
                if ($readOnly) {
                    $targetPortalGroupPath = self::ISCSI_PATH . '/' . $target . '/tpg1';
                    $this->setTpgReadOnly($targetPortalGroupPath);
                }

                $command[] = '-1 '; // this -1 file size will be ignored, since the file already exists
                $command[] = $writeBack ? 'true' : 'false';
                $command[] = 'true'; // this is the fileio 'create' command's [sparse] parameter
                break;
        }
        if ($wwn) {
            $command[] = $wwn;
        }

        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get($command);
            $process->run();
        } finally {
            $this->configFSLock->unlock();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to create backstore ' . $backstoreName .
                ' for iSCSI target ' . $target .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        $backstorePath = self::BACKSTORES_PATH . '/' . $accessType . '/' . $backstoreName;
        if (!empty($attributes)) {
            $this->setBackstoreAttributes($backstorePath, $attributes);
        }
        return $backstorePath;
    }

    /**
     * Delete the backstore
     *
     * @param string $target Target's iSCSI Qualified Name (IQN)
     * @param string $backstoreName Backstore name
     * @param string $path Path that the backstore should be associated with
     */
    public function deleteBackstore(
        string $backstoreName,
        string $path,
        string $target = '[UNKNOWN]'
    ) {
        $accessType = $this->getAccessType($path);

        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get([
                self::SUDO,
                self::TARGETCLI_COMMAND,
                self::BACKSTORES_PATH . '/' . $accessType,
                self::TARGETCLI_DELETE,
                $backstoreName
            ]);
            $process->run();
        } finally {
            $this->configFSLock->unlock();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to delete backstore ' . $backstoreName .
                ' for iSCSI target ' . $target .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Create LUN
     *
     * @param string $target Target's iSCSI Qualified Name (IQN)
     * @param string $backstorePath Path to the backstore to associate with the LUN
     */
    public function createLun(string $target, string $backstorePath)
    {
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get([
                self::SUDO,
                self::TARGETCLI_COMMAND,
                self::ISCSI_PATH . '/' . $target . '/tpg1/luns',
                self::TARGETCLI_CREATE,
                $backstorePath
            ]);
            $process->run();
        } finally {
            $this->configFSLock->unlock();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to activate LUN for target ' . $target .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Delete LUN
     */
    public function deleteLun()
    {
        // todo: a LUN is currently deleted by deleting the backstore associated with it
        // targetcli /iscsi/iqn.2007-01.net.datto.dev.temp.roc-core-s3e36:agentcc4c4a7125ec4d039316c7f5eaeb5245/tpg1/luns delete lun1
    }

    /**
     * Set target portal group (tpg) attributes
     *
     * @param string $targetPortalGroupPath
     * @param array $attributes
     */
    public function setTargetPortalGroupPathAttributes(string $targetPortalGroupPath, array $attributes)
    {
        $normalizedAttributes = implode(' ', $attributes);

        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get([
                self::SUDO,
                self::TARGETCLI_COMMAND,
                $targetPortalGroupPath,
                'set attribute ' . $normalizedAttributes
            ]);
            $process->run();
        } finally {
            $this->configFSLock->unlock();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to set target attributes for tpg ' . $targetPortalGroupPath .
                ' with attributes ' . $normalizedAttributes .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Set tpg parameters
     *
     * @param string $targetPortalGroupPath
     * @param array $parameters
     */
    public function setTargetPortalGroupParameters(string $targetPortalGroupPath, array $parameters)
    {
        $normalizedParameters = implode(' ', $parameters);

        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get([
                self::SUDO,
                self::TARGETCLI_COMMAND,
                $targetPortalGroupPath,
                'set parameter ' . $normalizedParameters
            ]);
            $process->run();
        } finally {
            $this->configFSLock->unlock();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to set target parameters for tpg ' . $targetPortalGroupPath .
                ' with parameters ' . $normalizedParameters .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Set tpg auth parameters
     *
     * @param string $targetPortalGroupPath
     * @param array $parameters
     */
    public function setTargetPortalGroupAuthParameters(string $targetPortalGroupPath, array $parameters)
    {
        $normalizedParameters = implode(' ', $parameters);

        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get([
                self::SUDO,
                self::TARGETCLI_COMMAND,
                $targetPortalGroupPath,
                'set auth ' . $normalizedParameters
            ]);
            $process->run();
        } finally {
            $this->configFSLock->unlock();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to set target auth parameters for tpg ' . $targetPortalGroupPath .
                ' with parameters ' . $normalizedParameters .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Get the tpg auth parameters for the specified iSCSI target.
     *
     * @param string $targetPortalGroupPath
     * @return string[]
     */
    public function getTargetPortalGroupAuthParameters(string $targetPortalGroupPath): array
    {
        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get([
                self::SUDO,
                self::TARGETCLI_COMMAND,
                $targetPortalGroupPath,
                'get auth'
            ]);
            $process->run();
        } finally {
            $this->configFSLock->unlock();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to get target auth parameters for tpg ' . $targetPortalGroupPath .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        $processOutput = $process->getOutput();
        return $this->parseGetAuthOutput($processOutput);
    }

    /**
     * Parse the output from the targetcli get auth command.
     *
     * @param string $getAuthOutput
     * @return string[]
     */
    private function parseGetAuthOutput(string $getAuthOutput): array
    {
        $chapAuthParameters = [];

        $lines = explode(PHP_EOL, $getAuthOutput);
        foreach ($lines as $line) {
            // Match a line like "mutual_userid=datto", or "password="
            if (preg_match("~^([a-zA-Z_-]+)=(.*)$~", $line, $matches)) {
                $key = $matches[1];
                $value = $matches[2];
                $chapAuthParameters[$key] = $value;
            }
        }

        return $chapAuthParameters;
    }

    /**
     * Enable or disable a target's TPG
     *
     * This regulates whether sessions are allowed on the target.
     *
     * @param string $targetPortalGroupPath
     * @param bool $enabled set to TRUE to enable the target's TPG
     */
    public function setTpgState(string $targetPortalGroupPath, bool $enabled)
    {
        $command = $enabled ? 'enable' : 'disable';

        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get([
                self::SUDO,
                self::TARGETCLI_COMMAND,
                $targetPortalGroupPath,
                $command
            ]);
            $process->run();
        } finally {
            $this->configFSLock->unlock();
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to ' . $command . ' tpg for ' . $targetPortalGroupPath .
                ': ' . ($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Set the tpg to read only
     *
     * @param string $tpgPath
     */
    private function setTpgReadOnly(string $tpgPath)
    {
        $attributes = ['demo_mode_write_protect=1'];
        $this->setTargetPortalGroupPathAttributes($tpgPath, $attributes);
    }

    /**
     * Set the backstore attributes
     *
     * @param string $backstorePath
     * @param array $attributes
     */
    private function setBackstoreAttributes(string $backstorePath, array $attributes)
    {
        $normalizedAttributes = implode(' ', $attributes);

        $this->configFSLock->assertExclusiveAllowWait(LockInfo::CONFIGFS_LOCK_WAIT_TIMEOUT);
        try {
            $process = $this->processFactory->get([
                self::SUDO,
                self::TARGETCLI_COMMAND,
                $backstorePath,
                'set attribute ' . $normalizedAttributes
            ]);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException(
                    'Failed to set backstore attributes for backstore ' . $backstorePath .
                    ' with attributes ' . $normalizedAttributes .
                    ': ' . ($process->getErrorOutput() ?: $process->getOutput())
                );
            }
        } finally {
            $this->configFSLock->unlock();
        }
    }

    /**
     * Get the access type associated with the file type for the given path
     *
     * @param string $path
     * @return string
     */
    private function getAccessType(string $path): string
    {
        return $this->filesystem->isBlockDevice($path) ? self::ACCESS_TYPE_BLOCK : self::ACCESS_TYPE_FILEIO;
    }
}
