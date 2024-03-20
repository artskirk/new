<?php
namespace Datto\Asset\RecoveryPoint;

use Datto\Config\JsonConfigRecord;
use Datto\Resource\DateTimeService;

/**
 * Represents 30 day store of recovery point metadata
 */
class RecoveryPointHistoryRecord extends JsonConfigRecord
{
    const MAX_HISTORY_IN_DAYS = 90;

    const KEY = "recoveryPointHistory";
    const TRANSFER = "transfer";
    const TOTAL_USED = "totalUsed";

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var array */
    private $contents = [];

    /**
     * @param DateTimeService|null $dateTimeService
     */
    public function __construct(
        DateTimeService $dateTimeService = null
    ) {
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
    }

    /**
     * Get the contents of the recovery point history file
     *
     * @return array
     */
    public function getContents(): array
    {
        return $this->contents;
    }

    /**
     * Gets all values for a single field
     * @param string $column
     * @return array
     */
    public function getColumn(string $column): array
    {
        $out = [];
        foreach ($this->contents as $epoch => $contents) {
            if (isset($contents[$column])) {
                $out[$epoch] = $contents[$column];
            }
        }

        return $out;
    }

    /**
     * Adds a transfer value to the array
     *
     * @param int $epoch
     * @param int $bytes
     */
    public function addTransfer(int $epoch, int $bytes): void
    {
        $this->contents[$epoch][self::TRANSFER] = $bytes;
    }

    /**
     * Adds a total used value to the array
     *
     * @param int $epoch
     * @param int $bytes
     */
    public function addTotalUsed(int $epoch, int $bytes): void
    {
        $this->contents[$epoch][self::TOTAL_USED] = $bytes;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $this->prune();

        return $this->contents;
    }

    /**
     * @inheritdoc
     */
    public function getKeyName(): string
    {
        return static::KEY;
    }

    /**
     * @inheritdoc
     */
    protected function load(array $vals)
    {
        $this->contents = $vals;
    }

    /**
     * Prune everything older than 90 days
     */
    private function prune(): void
    {
        $today = $this->dateTimeService->now();

        ksort($this->contents);

        foreach ($this->contents as $epoch => $stuff) {
            $point = $this->dateTimeService->fromTimestamp($epoch);
            $interval = $today->diff($point);
            $days = $interval->format("%a");

            if ($days <= self::MAX_HISTORY_IN_DAYS) {
                break;
            }

            unset($this->contents[$epoch]);
        }
    }
}
