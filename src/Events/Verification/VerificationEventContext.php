<?php

namespace Datto\Events\Verification;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\RemoveNullProperties;
use Datto\Events\Common\ResultsContext;
use Datto\Events\EventContextInterface;
use Datto\Verification\Notification\VerificationResults;

/**
 * Class to implement the context node included in verification Event
 */
class VerificationEventContext extends AbstractEventNode implements EventContextInterface
{
    use RemoveNullProperties;

    /** @var ResultsContext results of all of the Transaction's stages */
    protected $stages;

    /**
     * @var string|null failure analysis of the screenshot image
     * `VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE` if the screenshot was successful
     */
    protected $failureAnalysis = VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;

    public function __construct(
        ResultsContext $stages,
        ?string $failureAnalysis = VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE
    ) {
        $this->stages = $stages;
        $this->failureAnalysis = $failureAnalysis;
    }
}
