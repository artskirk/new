<?php

namespace Datto\Utility\Roundtrip;

/**
 * Model class containing enclosure data from roundtrip-ng
 *
 * @author Stephen Allan <sallan@datto.com>
 * @codeCoverageIgnore
 */
class Enclosure
{
    /** @var string */
    private $id;

    /** @var int */
    private $physicalSize;

    /**
     * @param string $id
     * @param int $physicalSize
     */
    public function __construct(string $id, int $physicalSize)
    {
        $this->id = $id;
        $this->physicalSize = $physicalSize;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return int
     */
    public function getPhysicalSize(): int
    {
        return $this->physicalSize;
    }
}
