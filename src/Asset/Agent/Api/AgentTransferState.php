<?php

namespace Datto\Asset\Agent\Api;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * An enumeration of agent transfer state types
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 *
 * @method static AgentTransferState NONE()
 * @method static AgentTransferState ACTIVE()
 * @method static AgentTransferState FAILED()
 * @method static AgentTransferState COMPLETE()
 * @method static AgentTransferState ROLLBACK()
 */
class AgentTransferState extends AbstractEnumeration
{
    const NONE = 0;
    const ACTIVE = 1;
    const FAILED = 2;
    const COMPLETE = 3;
    const ROLLBACK = 4;
}
