<?php

namespace Datto\Asset\Agent;

use Exception;

/**
 * Thrown when the agent isn't actually paired to the device, even though the device thinks it is.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class AgentNotPairedException extends Exception
{
    // No code for you!
}
