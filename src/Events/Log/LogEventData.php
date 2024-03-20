<?php

namespace Datto\Events\Log;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\AssetData;
use Datto\Events\Common\PlatformData;
use Datto\Events\Common\RemoveNullProperties;
use Datto\Events\EventDataInterface;

/**
 * Class to implement the data node included in log event
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class LogEventData extends AbstractEventNode implements EventDataInterface
{
    use RemoveNullProperties;

    /** @var int */
    protected $index;

    /** @var PlatformData */
    protected $platform;

    /** @var LogData */
    protected $log;

    /** @var AssetData */
    protected $asset;

    public function __construct(
        int $index,
        PlatformData $platform,
        LogData $log,
        AssetData $asset = null
    ) {
        $this->index = $index;
        $this->platform = $platform;
        $this->log = $log;
        $this->asset = $asset;
    }

    /** @inheritDoc */
    public function getSchemaVersion(): int
    {
        return 11;
    }
}
