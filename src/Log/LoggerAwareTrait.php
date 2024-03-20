<?php

namespace Datto\Log;

use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;

/**
 * Basic Implementation of LoggerAwareInterface.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
trait LoggerAwareTrait
{
    /**
     * The logger instance. Will be set by the Symfony Container automatically, via `setLogger()`.
     *
     * Psalm will treat this as potentially uninitialized, and cause a warning in every class using this trait,
     * so we suppress the warning for this property.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    protected DeviceLoggerInterface $logger;

    /**
     * Sets a logger - Needs to be a DeviceLoggerInterface, or we will throw.  We keep the typehint as LoggerInterface
     * on the parameter so that we can comply with the Psr LoggerAwareInterface.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        if (!($logger instanceof DeviceLoggerInterface)) {
            throw new InvalidTypeException('setLogger expected type ' . DeviceLoggerInterface::class . ', received type ' . get_class($logger));
        }
        $this->logger = $logger;
    }
}
