<?php

namespace Datto\Events\CertificatesInUse;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\AssetData;
use Datto\Events\Common\RemoveNullProperties;

/**
 * Contains information about an agent and the trusted root certificate it is current using
 */
class AgentCertificateData extends AbstractEventNode
{
    use RemoveNullProperties;

    /** @var AssetData The agent data that the trusted root certificate is associated with */
    protected $agentData;

    /** @var string Hash of the certificate in use for this agent */
    protected $trustedRootCertificateHash;

    public function __construct(
        AssetData $agentData,
        string $trustedRootCertificateHash
    ) {
        $this->agentData = $agentData;
        $this->trustedRootCertificateHash = $trustedRootCertificateHash;
    }
}
