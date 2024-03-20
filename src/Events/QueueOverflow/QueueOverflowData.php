<?php

namespace Datto\Events\QueueOverflow;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\PlatformData;
use Datto\Events\EventDataInterface;

/**
 * Class to implement the data node included in event queue overflow event
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class QueueOverflowData extends AbstractEventNode implements EventDataInterface
{
    /** @var PlatformData */
    protected $platform;

    public function __construct(
        PlatformData $platform
    ) {
        $this->platform = $platform;
    }

    public function getSchemaVersion(): int
    {
        return 5;
    }
}
