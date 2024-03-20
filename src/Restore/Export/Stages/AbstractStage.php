<?php

namespace Datto\Restore\Export\Stages;

use Datto\Log\LoggerAwareTrait;
use Datto\Restore\Export\Context;
use Datto\System\Transaction\Stage;
use Psr\Log\LoggerAwareInterface;

/**
 * Base class for any shared functionality between export stages.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
abstract class AbstractStage implements Stage, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     */
    protected Context $context;

    /**
     * @inheritDoc
     */
    public function setContext($context)
    {
        $this->context = $context;
    }
}
