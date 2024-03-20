<?php

namespace Datto\Filesystem;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Utility\Filesystem\AbstractFuseOverlayMount;
use Datto\Common\Utility\Mount\MountUtility;
use Datto\Common\Utility\Process\RetryHandler;
use Datto\Common\Utility\Process\SystemCtl;
use Datto\Common\Utility\Process\SystemdRunner;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating instances of TransparentMount.
 *
 * Note: constructor dependency injection parameters are optional in order to
 * simplify instantiation of this class in RemoteHypervisorStorageFactory.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class TransparentMountFactory
{
    private LoggerInterface $logger;
    private Filesystem $filesystem;
    private ProcessFactory $processFactory;
    private SystemdRunner $systemdRunner;
    private RetryHandler $retryHandler;
    private MountUtility $mountUtility;
    private SystemCtl $systemCtl;

    public function __construct(
        LoggerInterface $logger,
        Filesystem $filesystem,
        ProcessFactory $processFactory,
        SystemdRunner $systemdRunner,
        RetryHandler $retryHandler,
        MountUtility $mountUtility,
        SystemCtl $systemCtl
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->processFactory = $processFactory;
        $this->systemdRunner = $systemdRunner;
        $this->retryHandler = $retryHandler;
        $this->mountUtility = $mountUtility;
        $this->systemCtl = $systemCtl;
    }

    public function create(
        int $attemptLimit = AbstractFuseOverlayMount::DEFAULT_UNMOUNT_ATTEMPTS,
        int $attemptWaitSeconds = AbstractFuseOverlayMount::DEFAULT_UNMOUNT_ATTEMPT_INTERVAL_SEC,
        string $mountBase = AbstractFuseOverlayMount::DEFAULT_MOUNT_DIR
    ) : TransparentMount {
        return new TransparentMount(
            $this->logger,
            $this->filesystem,
            $this->processFactory,
            $this->systemdRunner,
            $this->retryHandler,
            $this->mountUtility,
            $this->systemCtl,
            $attemptLimit,
            $attemptWaitSeconds,
            $mountBase
        );
    }
}
