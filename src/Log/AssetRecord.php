<?php

namespace Datto\Log;

/**
 * Class that represents a record from the asset log.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class AssetRecord
{
    /** @var int */
    private $timestamp;

    /** @var string */
    private $alertCode;

    /** @var string */
    private $message;

    /** @var string */
    private $userName;

    /** @var bool|null */
    private $important = null;

    /**
     * @param int $timestamp
     * @param string $alertCode
     * @param string $message
     * @param string $userName
     */
    public function __construct(int $timestamp, string $alertCode, string $message, string $userName)
    {
        $this->timestamp = $timestamp;
        $this->alertCode = $alertCode;
        $this->message = $message;
        $this->userName = $userName;
    }

    /**
     * @return int
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * Full alert code, eg. 'MBR1000'
     *
     * @return string
     */
    public function getAlertCode(): string
    {
        return $this->alertCode;
    }

    /**
     * Integer portion of the alert code, eg. '1000'
     *
     * @return int
     */
    public function getCode(): int
    {
        return (int)substr($this->alertCode, 3);
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return string
     */
    public function getUserName(): string
    {
        return $this->userName;
    }

    /**
     * @return bool|null
     */
    public function isImportant()
    {
        return $this->important;
    }

    /**
     * @param bool $important
     */
    public function setImportant(bool $important)
    {
        $this->important = $important;
    }

    /**
     * @return mixed[]
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'alertCode' => $this->alertCode,
            'code' => $this->getCode(),
            'message' => $this->message,
            'userName' => $this->userName,
            'important' => $this->important
        ];
    }

    /**
     * @param AssetRecord[] $records
     * @return array
     */
    public static function manyToArray(array $records): array
    {
        $arrays = [];

        foreach ($records as $record) {
            $arrays[] = $record->toArray();
        }

        return $arrays;
    }
}
