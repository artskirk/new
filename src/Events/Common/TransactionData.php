<?php

namespace Datto\Events\Common;

use DateTime;
use DateTimeInterface;
use Datto\Events\AbstractEventNode;

/**
 * Details about the execution of a Transaction
 */
class TransactionData extends AbstractEventNode
{
    /**
     * @var string description of the Transaction's result
     *
     * This should probably come from an enum to make it easier to monitor and visualize.
     */
    protected $result;

    /** @var string time when the Transaction's commit began */
    protected $startTimestamp;

    /** @var string time when the Transaction's commit completed */
    protected $endTimestamp;

    public function __construct(string $result, DateTimeInterface $startTimestamp, DateTimeInterface $endTimestamp)
    {
        $this->result = $result;
        $this->startTimestamp = $startTimestamp->format(DateTime::ISO8601);
        $this->endTimestamp = $endTimestamp->format(DateTime::ISO8601);
    }
}
