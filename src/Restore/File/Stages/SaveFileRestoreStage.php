<?php

namespace Datto\Restore\File\Stages;

use Datto\Resource\DateTimeService;
use Datto\Restore\File\AbstractFileRestoreStage;
use Datto\Restore\File\FileRestoreContext;
use Datto\Restore\Restore;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Utility\File\LockInfo;
use Datto\Log\DeviceLoggerInterface;
use Datto\Restore\RestoreFactory;

/**
 * Save/persist UI restore entry for file restore.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class SaveFileRestoreStage extends AbstractFileRestoreStage
{
    private RestoreService $restoreService;
    private RestoreFactory $restoreFactory;
    private DateTimeService $dateTimeService;
    private LockFactory $lockFactory;
    private Lock $lock;

    public function __construct(
        FileRestoreContext $context,
        DeviceLoggerInterface $logger,
        RestoreService $restoreService,
        RestoreFactory $restoreFactory,
        DateTimeService $dateTimeService,
        LockFactory $lockFactory
    ) {
        parent::__construct($context, $logger);
        $this->restoreService = $restoreService;
        $this->restoreFactory = $restoreFactory;
        $this->dateTimeService = $dateTimeService;
        $this->lockFactory = $lockFactory;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $this->lock = $this->lockFactory->create(LockInfo::RESTORE_LOCK_FILE);
        $this->lock->assertExclusiveAllowWait(Lock::DEFAULT_LOCK_WAIT_TIME);

        $this->logger->debug("FIR0011 Creating restore entry ...");

        $options = [
            'shareName' => $this->context->getSambaShareName()
        ];

        $sftpCredentials = $this->context->getSftpCredentials();
        if ($sftpCredentials !== null) {
            $options['sftp']['username'] = $sftpCredentials->getUsername();
            $options['sftp']['password'] = $sftpCredentials->getPassword();
        }

        $restore = $this->restoreFactory->create(
            $this->context->getAsset()->getKeyName(),
            $this->context->getSnapshot(),
            RestoreType::FILE,
            $this->dateTimeService->getTime(),
            $options
        );

        $this->restoreService->getAll();
        $this->restoreService->add($restore);
        $this->restoreService->save();

        $this->context->setRestore($restore);
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        $this->lock->unlock();
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        $restore = $this->restoreService->find(
            $this->context->getAsset()->getKeyName(),
            $this->context->getSnapshot(),
            RestoreType::FILE
        );

        if ($restore) {
            $this->restoreService->remove($restore);
            $this->restoreService->save();
        }

        $this->lock->unlock();
    }
}
