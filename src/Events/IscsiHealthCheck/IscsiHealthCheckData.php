<?php

namespace Datto\Events\IscsiHealthCheck;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\PlatformData;
use Datto\Events\EventDataInterface;

/**
 * Class to implement the data node included in IscsiHealthCheckEvents
 *
 * @author Mark Blakley <mblakley@datto.com>
 * @author Matt Coleman <matt@datto.com>
 */
class IscsiHealthCheckData extends AbstractEventNode implements EventDataInterface
{
    /** @var int The PID of the first hung process in the list*/
    protected $hungProcessPid;

    /** @var string The WCHAN of the first hung process in the list */
    protected $hungProcessWchan;

    /** @var string The datetime that the first hung process in the list was started */
    protected $hungProcessStartDateTime;

    /** @var int The total number of hung processes in the list */
    protected $numHungProcesses;

    /** @var PlatformData */
    protected $platform;

    public function __construct(
        int $hungProcessPid,
        string $hungProcessWchan,
        string $hungProcessStartDateTime,
        int $numHungProcesses,
        PlatformData $platform
    ) {
        $this->hungProcessPid = $hungProcessPid;
        $this->hungProcessWchan = $hungProcessWchan;
        $this->hungProcessStartDateTime = $hungProcessStartDateTime;
        $this->numHungProcesses = $numHungProcesses;
        $this->platform = $platform;
    }

    /**
     * @inheritDoc
     */
    public function getSchemaVersion(): int
    {
        return 6;
    }
}
