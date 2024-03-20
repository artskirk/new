<?php

namespace Datto\Ipmi;

class IpmiUser
{
    /** @var string */
    private $name;

    /** @var int */
    private $userId;

    public function __construct(
        $name,
        $userId
    ) {
        $this->name = $name;
        $this->userId = $userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}
