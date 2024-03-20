<?php

namespace Datto\Restore\Differential\Rollback\Stages;

use Datto\Restore\Differential\Rollback\DifferentialRollbackContext;
use Datto\System\Transaction\Stage;
use Datto\Log\DeviceLoggerInterface;

/**
 * Base stage for differential rollbacks.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
abstract class AbstractStage implements Stage
{
    /** @var DifferentialRollbackContext */
    protected $context;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /**
     * @param DifferentialRollbackContext $context
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(DifferentialRollbackContext $context, DeviceLoggerInterface $logger)
    {
        $this->context = $context;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function setContext($context)
    {
        // not yet implemented
    }
}
