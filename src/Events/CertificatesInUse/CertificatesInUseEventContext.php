<?php

namespace Datto\Events\CertificatesInUse;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\RemoveNullProperties;
use Datto\Events\EventContextInterface;

/**
 * Class to implement the context node included in CertificatesInUseEvents
 *
 */
class CertificatesInUseEventContext extends AbstractEventNode implements EventContextInterface
{
    use RemoveNullProperties;


    /** @var bool Success or failure of the certificate update process */
    protected $eventSuccess;

    /** @var string Human readable output result of the certificate update process */
    protected $eventMessage;

    /**
     * @var string|null The message from the Exception that was thrown while running the certificate update process.
     * Expected to be null if no Exception was thrown.
     */
    protected $exceptionMessage;

    /**
     * CertificatesInUseEventContext contains the non-indexed data from an certificate in use event.  This is normally used for
     * more detailed investigations into an individual run of the certificates update process.
     *
     * @param bool $eventSuccess
     * @param string $eventMessage
     * @param string|null $exceptionMessage
     */
    public function __construct(
        bool $eventSuccess,
        string $eventMessage,
        string $exceptionMessage = null
    ) {
        $this->eventSuccess = $eventSuccess;
        $this->eventMessage = $eventMessage;
        $this->exceptionMessage = $exceptionMessage;
    }
}
