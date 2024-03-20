<?php

namespace Datto\Service\AssetManagement\Create\Stages;

use Datto\Service\AssetManagement\Create\CreateAgentContext;
use Datto\System\Transaction\Stage;
use Exception;

/**
 * Abstract stage for Agent Creation Transaction
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
abstract class AbstractCreateStage implements Stage
{
    /** @var CreateAgentContext */
    protected $context;

    /**
     * Sets context needed for the stage to run
     *
     * @param $context
     */
    public function setContext($context)
    {
        if ($context instanceof CreateAgentContext) {
            $this->context = $context;
        } else {
            throw new Exception('Expected CreateAgentContext, received ' . get_class($context));
        }
    }
}
