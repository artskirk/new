<?php

namespace Datto\Restore\File;

use Datto\System\Transaction\Stage;
use Datto\Log\DeviceLoggerInterface;

/**
 * Base stage for file restores.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
abstract class AbstractFileRestoreStage implements Stage
{
    /** @var FileRestoreContext */
    protected $context;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /**
     * @param FileRestoreContext $context
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(FileRestoreContext $context, DeviceLoggerInterface $logger)
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
