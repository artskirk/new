<?php

namespace Datto\System;

use Datto\Asset\AssetService;
use Datto\Backup\BackupManager;
use Datto\Backup\BackupManagerFactory;
use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Resource\Sleep;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Verification\VerificationCleanupManager;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Class that handles clean shutdown of the device. Separated from PowerManager as that is manually triggered,
 * this class is called automatically by a service through a command during device shutdown.
 */
class ShutdownService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private RestoreService $restoreService;
    private BackupManagerFactory $backupManagerFactory;
    private ProcessFactory $processFactory;
    private AssetService $assetService;
    private Sleep $sleep;
    private VerificationCleanupManager $cleanupManager;

    public function __construct(
        RestoreService $restoreService,
        BackupManagerFactory $backupManagerFactory,
        ProcessFactory $processFactory,
        AssetService $assetService,
        Sleep $sleep,
        VerificationCleanupManager $cleanupManager
    ) {
        $this->restoreService = $restoreService;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->processFactory = $processFactory;
        $this->assetService = $assetService;
        $this->sleep = $sleep;
        $this->cleanupManager = $cleanupManager;
    }

    /**
     * Run the cleanup steps for a shutdown
     * This should be called only during a shutdown, when Systemctl::isSystemRunning() returns false
     */
    public function shutdownCleanup()
    {
        try {
            /**
             * A timeout in datto-device-shutdown.service will kill this process before its finished, these are
             * ordered so that the most likely to be messed up by this are done first.
             */
            $this->stopVirtualizations();
            $this->stopScreenshots();
            $this->stopBackups();
        } catch (Throwable $e) {
            $this->logger->error(
                'SDS0001 Error encountered attempting to gracefully clean up the device before a shut down',
                ['exception' => $e]
            );
            throw new Exception('There was an error cleaning up the device before a shutdown');
        }
    }

    /**
     * Stop running virtualizations.
     */
    private function stopVirtualizations()
    {
        $virtRestores = $this->restoreService->getAllForAssets(null, [RestoreType::ACTIVE_VIRT, RestoreType::RESCUE]);
        foreach ($virtRestores as $restore) {
            if ($restore->virtualizationIsRunning()) {
                // Stopping each restore vm directly in a loop causes a segfault in libvirt-php.
                // Use a separate process for each vm to work around the issue.
                // We use --skipRestoreUpdate to leave the UIRestore's vmPoweredOn state the same so the vm gets
                // turned back on after the siris boots.
                $process = $this->processFactory->get(
                    ['snapctl', 'virtualization:stop', $restore->getAssetKey(), '--skipRestoreUpdate']
                );
                try {
                    $process->mustRun();
                } catch (Throwable $e) {
                    $this->logger->warning(
                        'SDS0002 Failed to shutdown virt',
                        ['assetKey' => $restore->getAssetKey(), 'processError' => $process->getErrorOutput()]
                    );
                }
            }
        }
    }

    /**
     * Stops all running screenshots.
     */
    private function stopScreenshots()
    {
        $this->cleanupManager->stopAllVerifications();
    }

    /**
     * Stops all currently running backup operations.
     *
     * @return void
     */
    private function stopBackups()
    {
        $assets = $this->assetService->getAllActiveLocal();
        $cancelingBackupManagers = [];
        foreach ($assets as $asset) {
            $backupManager = $this->backupManagerFactory->create($asset);
            if ($backupManager->isRunning()) {
                $backupManager->cancelBackup();
                $cancelingBackupManagers[] = $backupManager;
            }
        }

        $timeout = 300 * 1000; // Wait a maximum of 5 minutes for all backups to cancel
        while (!empty($cancelingBackupManagers) && $timeout > 0) {
            $this->sleep->msleep(250);
            $timeout -= 250;

            $cancelingBackupManagers = array_filter($cancelingBackupManagers, function (BackupManager $backupManager) {
                return $backupManager->isRunning();
            });
        }
    }
}
