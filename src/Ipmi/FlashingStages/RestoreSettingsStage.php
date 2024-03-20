<?php

namespace Datto\Ipmi\FlashingStages;

use Datto\Ipmi\IpmiService;
use Datto\Ipmi\IpmiSettings;
use Datto\System\Transaction\Stage;
use Datto\Log\DeviceLoggerInterface;

/**
 * Restore IPMI settings back to their original state.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RestoreSettingsStage implements Stage
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var IpmiService */
    private $ipmiService;

    /** @var IpmiSettings */
    private $ipmiSettings;

    /**
     * @param DeviceLoggerInterface $logger
     * @param IpmiService $ipmiService
     * @param IpmiSettings $ipmiSettings
     */
    public function __construct(DeviceLoggerInterface $logger, IpmiService $ipmiService, IpmiSettings $ipmiSettings)
    {
        $this->logger = $logger;
        $this->ipmiService = $ipmiService;
        $this->ipmiSettings = $ipmiSettings;
    }

    /**
     * @inheritDoc
     */
    public function setContext($context)
    {
        // not yet implemented
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $this->logger->info('RSS0001 Restoring IPMI settings ...');

        $this->ipmiService->setNetworkSettings(
            !$this->ipmiSettings->isStatic(),
            $this->ipmiSettings->getIpAddress(),
            $this->ipmiSettings->getSubnetMask(),
            $this->ipmiSettings->getGateway()
        );
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // Nothing
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        // Nothing
    }
}
