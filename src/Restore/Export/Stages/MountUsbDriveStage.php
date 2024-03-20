<?php

namespace Datto\Restore\Export\Stages;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Util\RetryHandler;
use Datto\Utility\Process\ProcessCleanup;

/**
 * Mount the USB drive to which exported images will be copied.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class MountUsbDriveStage extends AbstractStage
{
    const MOUNT_COMMAND = 'mount';
    const UNMOUNT_COMMAND = 'umount';
    const MKDIR_MODE = 0777;

    /**
     * The "umount" command will not return until the write cache has been
     * completely written to the USB drive.  It has been observed that the
     * default 60 second timeout is sometimes insufficient to allow the write
     * cache to finish writing on some drives.  The default timeout was
     * increased significantly here, which should avoid any future issues.
     */
    const UNMOUNT_TIMEOUT_SECONDS = 300;

    /** @var ProcessFactory */
    private $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var RetryHandler */
    private $retryHandler;

    /** @var ProcessCleanup */
    private $processCleanup;

    public function __construct(
        ProcessFactory $processFactory,
        Filesystem $filesystem,
        RetryHandler $retryHandler,
        ProcessCleanup $processCleanup
    ) {
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
        $this->retryHandler = $retryHandler;
        $this->processCleanup = $processCleanup;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $this->filesystem->mkdirIfNotExists($this->context->getUsbInformation()->getMountPoint(), false, self::MKDIR_MODE);
        $process = $this->processFactory->get([
                static::MOUNT_COMMAND,
                $this->context->getUsbInformation()->getPartition(),
                $this->context->getUsbInformation()->getMountPoint()
        ]);

        $this->retryHandler->executeAllowRetry(function () use ($process) {
            $process->mustRun();
        });
    }

    /**
     * {@inheritdoc}
     *
     * Note: the "umount" command will not return until the write cache has
     * been completely written to the USB drive.
     */
    public function cleanup()
    {
        $this->processCleanup->waitUntilDirectoryNotBusy($this->context->getUsbInformation()->getMountPoint(), $this->logger);

        $process = $this->processFactory->get([
            static::UNMOUNT_COMMAND,
            $this->context->getUsbInformation()->getPartition()
        ]);
        $process->setTimeout(static::UNMOUNT_TIMEOUT_SECONDS);

        $this->retryHandler->executeAllowRetry(function () use ($process) {
            $process->mustRun();
            $this->filesystem->rmdir($this->context->getUsbInformation()->getMountPoint());
        });
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        $this->processCleanup->killProcessesUsingDirectory($this->context->getUsbInformation()->getMountPoint(), $this->logger);

        $this->cleanup();
    }
}
