<?php

namespace Datto\Events\Reboot;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\PlatformData;
use Datto\Events\EventDataInterface;

/**
 * Details about last reboot
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class RebootData extends AbstractEventNode implements EventDataInterface
{
    /** @var PlatformData */
    protected $platform;

    /** @var bool Whether it was a clean reboot */
    protected $wasClean;

    /** @var string Cause of the reboot */
    protected $cause;

    public function __construct(
        PlatformData $platform,
        bool $wasClean,
        string $cause
    ) {
        $this->platform = $platform;
        $this->wasClean = $wasClean;
        $this->cause = $cause;
    }

    public function getSchemaVersion(): int
    {
        return 6;
    }
}
