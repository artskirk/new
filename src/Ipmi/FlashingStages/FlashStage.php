<?php

namespace Datto\Ipmi\FlashingStages;

use Datto\Ipmi\IpmiFlasher;
use Datto\Ipmi\IpmiTool;
use Datto\System\Transaction\Stage;
use Datto\Common\Resource\Sleep;
use Datto\Log\DeviceLoggerInterface;

/**
 * Stage that handles flashing the IPMI firmware.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class FlashStage implements Stage
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var IpmiFlasher */
    private $flasher;

    /** @var IpmiTool */
    private $ipmiTool;

    /** @var Sleep */
    private $sleep;

    /**
     * @param DeviceLoggerInterface $logger
     * @param IpmiFlasher $flasher
     * @param IpmiTool $ipmiTool
     * @param Sleep $sleep
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        IpmiFlasher $flasher,
        IpmiTool $ipmiTool,
        Sleep $sleep
    ) {
        $this->logger = $logger;
        $this->flasher = $flasher;
        $this->ipmiTool = $ipmiTool;
        $this->sleep = $sleep;
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
        $this->logger->info('IFS0003 Flashing IPMI firmware with new version ...');
        $this->flasher->flash();

        $this->logger->info('IFS0004 Sleeping for 180 seconds as IPMI resets ...');
        $this->sleep->sleep(180);

        $this->logger->info('IFS0005 Resetting IPMI ...');
        $this->ipmiTool->bmcResetCold();

        $this->logger->info('IFS0006 Sleeping for 180 seconds as IPMI resets ...');
        $this->sleep->sleep(180);
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
