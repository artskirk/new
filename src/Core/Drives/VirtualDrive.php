<?php

namespace Datto\Core\Drives;

/**
 * An abstraction for a Virtual drive, like those that are enumerated by HyperV and ESX in virtualized
 * environment.
 */
class VirtualDrive extends AbstractDrive
{
    public const TYPE = 'virtual';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getModel(): string
    {
        return $this->smartData['scsi_model_name'] ?? parent::getModel();
    }

    public function getSerial(): string
    {
        // Virtual drives have no serial or any other identifying info. Just return N/A.
        return 'N/A';
    }

    public function isSelfTestPassed(): bool
    {
        // Virtual drives don't support any kind of self-test. To avoid any possible alerting,
        // just report that the test is passed.
        return true;
    }
}
