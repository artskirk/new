<?php

namespace Datto\Verification\Stages;

use Datto\Log\LoggerAwareTrait;
use Datto\System\Transaction\Stage;
use Datto\Verification\VerificationContext;
use Datto\Verification\VerificationResultType;
use Psr\Log\LoggerAwareInterface;
use Exception;

/**
 * Generic verification process stage.
 * This is used as the base class for all verification stages.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
abstract class VerificationStage implements Stage, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var VerificationContext Context used by all stages for verification. */
    protected $context;

    /** @var StageResult */
    protected $result;

    public function setContext($context)
    {
        if ($context instanceof VerificationContext) {
            $this->context = $context;
        } else {
            throw new Exception('Expected VerificationContext, received ' . get_class($context));
        }
    }

    abstract public function commit();

    abstract public function cleanup();

    public function rollback()
    {
        $this->cleanup();
    }

    /**
     * Get detailed results from the stage
     *
     * @return StageResult
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * Set the detailed result.
     * This is a helper function for the derived verification stages.
     *
     * @param VerificationResultType $resultType
     * @param string|null $errorMessage
     *   A message describing the failure for failure result types.
     *   NULL for success result types.
     * @param string|null $stacktrace of the exception that caused the error
     */
    protected function setResult(VerificationResultType $resultType, $errorMessage = null, $stacktrace = null)
    {
        if ($resultType === VerificationResultType::SUCCESS() && (!is_null($errorMessage) || !is_null($stacktrace))) {
            throw new Exception('$errorMessage and $stacktrace cannot be set in successful StageResults');
        }
        $this->result = new StageResult($resultType, $errorMessage, $stacktrace);
    }

    /**
     * Get the class name
     *
     * @return string
     *   The class name without the namespace.
     */
    public function getName()
    {
        $namespacedName = get_class($this);
        $lastSlash = strrpos($namespacedName, '\\');
        $className = substr($namespacedName, $lastSlash + 1);
        return $className;
    }
}
