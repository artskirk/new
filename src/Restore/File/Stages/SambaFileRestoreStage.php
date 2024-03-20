<?php

namespace Datto\Restore\File\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\SambaShareAuthentication;
use Datto\Restore\File\AbstractFileRestoreStage;
use Datto\Restore\File\FileRestoreContext;
use Datto\Samba\SambaManager;
use Datto\Resource\DateTimeService;
use Datto\Util\DateTimeZoneService;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Utility\File\LockInfo;
use Datto\Log\DeviceLoggerInterface;

/**
 * Create samba share for file restore.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class SambaFileRestoreStage extends AbstractFileRestoreStage
{
    const SAMBA_NAME_FORMAT = '%s-%s';

    /** @var SambaManager */
    private $sambaManager;

    /** @var SambaShareAuthentication */
    private $sambaShareAuthentication;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DateTimeZoneService */
    private $dateTimeZoneService;

    /** @var LockFactory */
    private $lockFactory;

    /** @var Lock */
    private $lock;

    public function __construct(
        FileRestoreContext $context,
        DeviceLoggerInterface $logger,
        SambaManager $sambaManager,
        SambaShareAuthentication $sambaShareAuthentication,
        DateTimeService $dateTimeService,
        DateTimeZoneService $dateTimeZoneService,
        LockFactory $lockFactory
    ) {
        parent::__construct($context, $logger);
        $this->sambaManager = $sambaManager;
        $this->sambaShareAuthentication = $sambaShareAuthentication;
        $this->dateTimeService = $dateTimeService;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->lockFactory = $lockFactory;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $this->lock = $this->lockFactory->create(LockInfo::SAMBA_LOCK_FILE);
        $this->lock->assertExclusiveAllowWait(Lock::DEFAULT_LOCK_WAIT_TIME);

        $name = $this->getName();
        $restoreMount = $this->context->getRestoreMount();
        $this->sambaManager->reload();
        $this->sambaManager->removeShare($name);
        $newShare = $this->sambaManager->createShare($name, $restoreMount);

        if ($newShare) {
            $this->logger->debug("FIR0010 Creating new samba share ...");

            $this->sambaShareAuthentication->setAuthenticationOptions(
                $this->context->getAsset()->getKeyName(),
                $newShare
            );
            $newShare->setProperties(array(
                'read only' => 'yes',
                'writable' => 'no'
            ));
            $this->sambaManager->sync();
        }

        $this->context->setSambaShareName($name);
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
        $this->sambaManager->removeShare($this->getName());
        $this->lock->unlock();
    }

    /**
     * @return string
     */
    private function getName()
    {
        $asset = $this->context->getAsset();

        $formattedDate = $this->dateTimeService->format(
            $this->dateTimeZoneService->localizedDateFormat('time-date-hyphenated'),
            $this->context->getSnapshot()
        );

        return sprintf(
            self::SAMBA_NAME_FORMAT,
            $asset instanceof Agent ? $asset->getHostname() : $asset->getDisplayName(),
            $formattedDate
        );
    }
}
