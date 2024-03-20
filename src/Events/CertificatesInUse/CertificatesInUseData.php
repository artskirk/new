<?php

namespace Datto\Events\CertificatesInUse;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\PlatformData;
use Datto\Events\Common\RemoveNullProperties;
use Datto\Events\EventDataInterface;

/**
 * Class to implement the data node included in CertificatesInUseEvents
 *
 * @author Dan Richardson <drichardson@datto.com>
 */
class CertificatesInUseData extends AbstractEventNode implements EventDataInterface
{
    use RemoveNullProperties;

    /** @var PlatformData */
    protected $platform;

    /** @var string Hash of the trusted root certificate currently in use for this device */
    protected $trustedRootCertificateHash;

    /** @var AgentCertificateData Agent this device is responsible for */
    protected $agentCertificateData;

    /**
     * @param PlatformData $platform
     * @param string $trustedRootCertificateHash
     * @param AgentCertificateData $agentCertificateData
     */
    public function __construct(
        PlatformData $platform,
        string $trustedRootCertificateHash = null,
        AgentCertificateData $agentCertificateData = null
    ) {
        $this->platform = $platform;
        $this->trustedRootCertificateHash = $trustedRootCertificateHash;
        $this->agentCertificateData = $agentCertificateData;
    }

    /**
     * @inheritDoc
     */
    public function getSchemaVersion(): int
    {
        return 9;
    }
}
