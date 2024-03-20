<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Backup\BackupException;
use Datto\Billing\Service;
use Datto\ZFS\ZfsDatasetService;
use Throwable;

/**
 * Preflight checks that are specific to rescue agents.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class RescueAgentPreflightChecks extends BackupStage
{
    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /** @var RescueAgentService */
    private $rescueAgentService;

    /** @var Service */
    private $billingService;

    public function __construct(
        ZfsDatasetService $zfsDatasetService,
        RescueAgentService $rescueAgentService,
        Service $billingService
    ) {
        $this->zfsDatasetService = $zfsDatasetService;
        $this->rescueAgentService = $rescueAgentService;
        $this->billingService = $billingService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->verifyDatasetsMounted();
        $this->verifyNotArchived();
        $this->verifyDeviceNotOutOfService();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        // Nothing to see here, move along
    }

    private function verifyDatasetsMounted()
    {
        if (!$this->zfsDatasetService->areBaseAgentDatasetsMounted()) {
            $this->context->getLogger()->critical("ZFS3985 File System is not properly mounted. Please try rebooting your device to remount. If error persists, contact Support");
            throw new BackupException("Filesystem is not mounted");
        }
    }

    private function verifyNotArchived()
    {
        try {
            $isArchived = $this->rescueAgentService->isArchived($this->context->getAsset()->getKeyName());
        } catch (Throwable $throwable) {
            throw new BackupException("Agent is not a rescue agent");
        }
        if ($isArchived) {
            $this->context->getLogger()->warning("BAK0623 Backup requested; ignoring request because agent is archived.");
            throw new BackupException("Rescue agent is archived");
        }
    }

    /**
     * Verify device is not out of service
     */
    private function verifyDeviceNotOutOfService()
    {
        if ($this->billingService->isOutOfService()) {
            $this->context->getLogger()->critical('BIL0003 Cannot perform backup due to out of service device.');
            throw new BackupException('Out of service device');
        }
    }
}
