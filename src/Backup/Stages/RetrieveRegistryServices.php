<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Windows\WindowsServiceRetriever;
use Throwable;

/**
 * This backup stage updated the Asset's cached list of services used during screenshot service verification.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class RetrieveRegistryServices extends BackupStage
{
    /** @var WindowsServiceRetriever */
    private $windowsServiceRetriever;

    public function __construct(WindowsServiceRetriever $windowsServiceRetriever)
    {
        $this->windowsServiceRetriever = $windowsServiceRetriever;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        try {
            $keyName = $this->context->getAsset()->getKeyName();
            $this->windowsServiceRetriever->refreshCachedRunningServices($keyName);
        } catch (Throwable $throwable) {
            // Log error and continue. Do not rollback entire backup transaction if this stage fails.
            $this->context->getLogger()->debug(
                'RRS0001 Failed to refresh running services cache: ' . $throwable->getMessage()
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }
}
