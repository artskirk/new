<?php

namespace Datto\Events\Verification;

use Datto\Events\AbstractEventNode;

/**
 * Details about the screenshot taken during verification
 */
class ScreenshotData extends AbstractEventNode
{
    /** @var bool TRUE if the screenshot indicates a successful boot */
    protected $success;

    /** @var int remaining timeout from the verification process */
    protected $timeout;

    /** @var string|null Hypervisor used for offloading a screenshot */
    protected $hypervisorHost;

    public function __construct(bool $success, int $timeout, string $hypervisorHost = null)
    {
        $this->success = $success;
        $this->timeout = $timeout;
        $this->hypervisorHost = $hypervisorHost;
    }
}
