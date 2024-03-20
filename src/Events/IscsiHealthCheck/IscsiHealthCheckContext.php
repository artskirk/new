<?php

namespace Datto\Events\IscsiHealthCheck;

use Datto\Events\AbstractEventNode;
use Datto\Events\EventContextInterface;

/**
 * Class to implement the context node included in IscsiHealthCheckEvents
 * @author Mark Blakley <mblakley@datto.com>
 */
class IscsiHealthCheckContext extends AbstractEventNode implements EventContextInterface
{
    /** @var string The full output of the command used to check for D state processes, containing the process list */
    protected $hungTargetCliProcessList;

    /**
     * @param string $hungTargetCliProcessList
     */
    public function __construct(string $hungTargetCliProcessList)
    {
        $this->hungTargetCliProcessList = $hungTargetCliProcessList;
    }
}
