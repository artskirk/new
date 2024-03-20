<?php

namespace Datto\Verification\Notification;

use Datto\Log\LoggerAwareTrait;
use Datto\System\Transaction\Stage;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Generic verification notification.
 * This is used as the base class for all verification notifications.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
abstract class VerificationNotification implements Stage, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var VerificationResults */
    protected $verificationResults;

    public function setContext($context)
    {
        if ($context instanceof VerificationResults) {
            $this->verificationResults = $context;
        } else {
            throw new Exception('Expected VerificationResults, received ' . get_class($context));
        }
    }

    abstract public function commit();

    public function cleanup()
    {
        // Do nothing
    }

    public function rollback()
    {
        // Do nothing
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
