<?php

namespace Datto\Asset\Agent\Api;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * An enumeration of agent transfer result types
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 *
 * @method static AgentTransferResult NONE()
 * @method static AgentTransferResult SUCCESS()
 * @method static AgentTransferResult FAILURE_CONNECTION()
 * @method static AgentTransferResult FAILURE_SNAPSHOT()
 * @method static AgentTransferResult FAILURE_BOTH()
 * @method static AgentTransferResult FAILURE_UNKNOWN()
 * @method static AgentTransferResult FAILURE_BAD_REQUEST()
 */
class AgentTransferResult extends AbstractEnumeration
{
    const NONE = 0;
    const SUCCESS = 1;
    const FAILURE_CONNECTION = 2;
    const FAILURE_SNAPSHOT = 3;
    const FAILURE_BOTH = 4;
    const FAILURE_UNKNOWN = 5;
    const FAILURE_BAD_REQUEST = 6;
}
