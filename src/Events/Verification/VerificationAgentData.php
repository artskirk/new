<?php

namespace Datto\Events\Verification;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\RemoveNullProperties;

/**
 * Details about the verification agent used in the asset's verification VM
 */
class VerificationAgentData extends AbstractEventNode
{
    use RemoveNullProperties;

    /** @var string type of verification agent, always 'Lakitu' */
    protected $type = 'Lakitu';

    /** @var bool TRUE if Lakitu was successfully injected */
    protected $injected;

    /** @var bool TRUE if Lakitu responded during the verification process */
    protected $responded;

    /** @var string|null Lakitu version or NULL if Lakitu did not respond */
    protected $version = null;

    public function __construct(bool $injected, bool $responded, string $version = null)
    {
        $this->injected = $injected;
        $this->responded = $responded;
        $this->version = $version;
    }
}
