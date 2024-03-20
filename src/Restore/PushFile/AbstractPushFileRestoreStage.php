<?php

namespace Datto\Restore\PushFile;

use Datto\Log\LoggerAwareTrait;
use Datto\System\Transaction\Stage;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Base stage for push file restores.
 *
 * @author Ryan Mack <rmack@datto.com>
 */
abstract class AbstractPushFileRestoreStage implements Stage, LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected PushFileRestoreContext $context;

    public function setContext($context)
    {
        if ($context instanceof PushFileRestoreContext) {
            $this->context = $context;
        } else {
            throw new Exception('Expected PushFileRestoreContext, received ' . get_class($context));
        }
    }

    public function rollback()
    {
        $this->cleanup();
    }
}
