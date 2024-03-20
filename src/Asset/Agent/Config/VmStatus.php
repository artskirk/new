<?php

namespace Datto\Asset\Agent\Config;

use Datto\Core\Configuration\ConfigRecordInterface;

/**
 * Config record representing virtualization status
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class VmStatus implements ConfigRecordInterface
{
    /** @var string */
    private $errorMessage;

    /** @var string */
    private $message;

    /** @var int */
    private $percentComplete;

    /**
     * @param string|null $message
     * @param int|null $percentComplete
     * @param string|null $errorMessage
     */
    public function __construct(string $message = null, int $percentComplete = null, string $errorMessage = null)
    {
        $this->setValues($message, $percentComplete, $errorMessage);
    }

    /**
     * Set values of the VM Status record
     *
     * @param string|null $message
     * @param int|null $percentComplete
     * @param string|null $errorMessage
     */
    private function setValues(string $message = null, int $percentComplete = null, string $errorMessage = null): void
    {
        $this->message = $message ?? '';
        $this->percentComplete = min(max($percentComplete ?? 0, 0), 100);
        $this->errorMessage = $errorMessage ?? '';
    }

    /**
     * @inheritdoc
     */
    public function getKeyName(): string
    {
        return 'vmStatus';
    }

    /**
     * @inheritdoc
     */
    public function unserialize(string $raw)
    {
        $vals = explode('|', $raw, 3);
        $this->setValues($vals[1] ?? null, $vals[0] ?? null, $vals[2] ?? null);
    }

    /**
     * @inheritdoc
     * @example 50|Creating VM|
     */
    public function serialize(): string
    {
        return $this->getPercentComplete() . '|' . $this->getMessage() . '|' . $this->getErrorMessage();
    }

    /**
     * Get the status message
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get the error message
     *
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Get the percent complete
     *
     * @return int
     */
    public function getPercentComplete(): int
    {
        return $this->percentComplete;
    }
}
