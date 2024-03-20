<?php

namespace Datto\DirectToCloud\Creation\Stages;

use Datto\DirectToCloud\Creation\Context;
use Datto\System\Transaction\Stage;
use Datto\Log\DeviceLoggerInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
abstract class AbstractStage implements Stage
{
    protected DeviceLoggerInterface $logger;
    protected Context $context;

    public function __construct(
        DeviceLoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function setContext($context)
    {
        $this->context = $context;
    }
}
