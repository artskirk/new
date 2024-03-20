<?php

namespace Datto\Verification\Stages;

use Datto\Verification\VerificationResultType;

/**
 * This class represents the results of the verification stages.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class StageResult
{
    /** @var VerificationResultType */
    private $resultType;

    /** @var string|null */
    private $errorMessage;

    /** @var string|null */
    private $stacktrace;

    /** @var StageResultDetails */
    private $details;

    /**
     * Construct a StageResult object.
     *
     * @param VerificationResultType $result Result of the stage
     * @param string|null $errorMessage Error message; NULL for success
     * @param string|null $stacktrace
     * @param StageResultDetails $details
     */
    public function __construct(
        VerificationResultType $result,
        $errorMessage = null,
        $stacktrace = null,
        StageResultDetails $details = null
    ) {
        $this->resultType = $result;
        $this->errorMessage = $errorMessage;
        $this->stacktrace = $stacktrace;
        $this->details = $details ?: new StageResultDetails();
    }

    /**
     * @return boolean True if the result was successful
     */
    public function didSucceed()
    {
        return $this->resultType === VerificationResultType::SUCCESS();
    }

    /**
     * @return VerificationResultType Result of the stage
     */
    public function getResultType()
    {
        return $this->resultType;
    }

    /**
     * @return string|null Error message
     */
    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    /**
     * @return null|string Stacktrace of the error if present, otherwise null.
     */
    public function getStacktrace()
    {
        return $this->stacktrace;
    }

    /**
     * @return StageResultDetails
     */
    public function getDetails()
    {
        return $this->details;
    }
}
