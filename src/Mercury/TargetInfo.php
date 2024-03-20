<?php

namespace Datto\Mercury;

/**
 * Represents a MercuryFTP target.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class TargetInfo
{
    /** @var string */
    private $name;

    /** @var string[] */
    private $luns;

    /** @var string|null */
    private $password;

    /**
     * @param string $name
     * @param array $luns
     * @param string|null $password
     */
    public function __construct(string $name, array $luns, $password = null)
    {
        $this->name = $name;
        $this->luns = $luns;
        $this->password = $password;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string[]
     */
    public function getLuns(): array
    {
        return $this->luns;
    }

    /**
     * @return string|null
     */
    public function getPassword()
    {
        return $this->password;
    }
}
