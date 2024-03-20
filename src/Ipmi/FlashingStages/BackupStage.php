<?php

namespace Datto\Ipmi\FlashingStages;

use Datto\Ipmi\IpmiFlasher;
use Datto\System\Transaction\Stage;
use Datto\Common\Resource\Sleep;
use Datto\Log\DeviceLoggerInterface;

/**
 * Stage that handles backing up and restoring the IPMI firmware.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class BackupStage implements Stage
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var IpmiFlasher */
    private $flasher;

    /** @var Sleep */
    private $sleep;

    /**
     * @param DeviceLoggerInterface $logger
     * @param IpmiFlasher $flasher
     * @param Sleep $sleep
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        IpmiFlasher $flasher,
        Sleep $sleep
    ) {
        $this->logger = $logger;
        $this->flasher = $flasher;
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
        $this->logger->info('IBS0001 Backing up IPMI firmware ...');
        $this->flasher->backup();

        $this->logger->info('IBS0002 Sleeping for 180 seconds as IPMI resets ...');
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
        try {
            $this->logger->info('IBS0003 Restoring IPMI firmware to backup ...');

            $this->flasher->restore();

            $this->logger->info('IBS0004 Sleeping for 180 seconds as IPMI resets ...');
            $this->sleep->sleep(180);
        } catch (\Throwable $e) {
            $this->logger->warning('IBS0005 Restoring IPMI firmware failed during rollback');
        }
    }
}
