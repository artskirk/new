<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\VerificationScreenshotResult;
use Datto\Config\DeviceState;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\LoggerAwareTrait;
use Datto\Samba\SambaManager;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Log\DeviceLoggerInterface;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Updates /etc/samba/smb.conf to include Datto specific settings
 *
 * @author Chris McGehee <cmcgehee@datto.com>
 */
class SambaConfigUpdateTask implements ConfigRepairTaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var DeviceState */
    private $deviceState;

    /** @var SambaManager */
    private $sambaManager;

    public function __construct(
        DeviceState $deviceState,
        SambaManager $sambaManager
    ) {
        $this->deviceState = $deviceState;
        $this->sambaManager = $sambaManager;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        // We only need to run this task once (assuming it's successful).
        if ($this->deviceState->has(DeviceState::SAMBA_CONFIG_UPDATED)) {
            return false;
        }

        try {
            $this->sambaManager->addInclude(SambaManager::DATTO_CONF_FILE, SambaManager::SAMBA_CONF_FILE);
            $this->sambaManager->sync();
        } catch (Exception $ex) {
            $this->logger->warning('CFG0024 Unable to update Samba configuration', ['exception' => $ex->getMessage()]);
            return false;
        }

        $this->deviceState->set(DeviceState::SAMBA_CONFIG_UPDATED, true);
        return true;
    }
}
