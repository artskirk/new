<?php

namespace Datto\Asset;

use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\Restore\File\FileRestoreService;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Psr\Log\LoggerAwareInterface;

/**
 * Remounts assets and restores after a reboot.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class RemountService implements LoggerAwareInterface
{
    const ASSETS_REMOUNTED_FLAG = '/dev/shm/assetsRemounted';

    use LoggerAwareTrait;

    private Filesystem $filesystem;
    private RestoreService $restoreService;
    private FileRestoreService $fileRestoreService;

    public function __construct(
        Filesystem $filesystem,
        RestoreService $restoreService,
        FileRestoreService $fileRestoreService
    ) {
        $this->filesystem = $filesystem;
        $this->restoreService = $restoreService;
        $this->fileRestoreService = $fileRestoreService;
    }

    /**
     * @return bool
     */
    public function remountAlreadyOccurred(): bool
    {
        return $this->filesystem->exists(self::ASSETS_REMOUNTED_FLAG);
    }

    /**
     * Remount restores.
     */
    public function remount(): bool
    {
        $this->filesystem->touch(self::ASSETS_REMOUNTED_FLAG);

        $this->logger->info('RMT0001 Starting asset remount ...');

        $success = $this->remountFileRestores();

        if ($success) {
            $this->logger->info('RMT0002 Asset remount complete.');
        } else {
            $this->logger->error('RMT0004 Asset remount unsuccessful.');
        }

        return $success;
    }

    /**
     * Remounts shares and restores if this routine hasn't already run this boot.
     */
    public function remountIfNeeded(): bool
    {
        if (!$this->remountAlreadyOccurred()) {
            return $this->remount();
        }

        return true;
    }

    private function remountFileRestores(): bool
    {
        try {
            $restores = $this->restoreService->getAllForAssets(
                null,
                [RestoreType::FILE, RestoreType::EXPORT, RestoreType::ACTIVE_VIRT, RestoreType::RESCUE]
            );
        } catch (\Exception $ex) {
            $this->logger->error('RMT0006 Errors were encountered while repairing restores', ['exception' => $ex]);
            return false;
        }

        $success = true;
        foreach ($restores as $restore) {
            $suffix = $restore->getSuffix();
            $uiKey = $restore->getUiKey();
            $this->logger->info('RMT0007 Repair beginning ...', ['uiKey', $uiKey]);
            try {
                if ($suffix === RestoreType::FILE) {
                    $this->fileRestoreService->repair($restore);
                } else {
                    $restore->repair();
                }
                $this->logger->info(
                    'RMT0008 The restore was successfully repaired',
                    ['uiKey' => $uiKey, 'suffix' => $suffix]
                );
            } catch (\Throwable $ex) {
                $this->logger->error(
                    'RMT0009 An error was encountered while trying to repair the restore',
                    ['uiKey', $uiKey, 'exception' => $ex]
                );
                $success = false;
            }
        }

        return $success;
    }
}
