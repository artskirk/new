<?php

namespace Datto\Security;

/**
 * @author Matthew Cheman <mcheman@datto.com>
 */
class PasswordStrength
{
    /** @var int (bad) 0 - 4 (good) inclusive */
    private $score;

    /** @var string[] Array of weakness constants from PasswordService */
    private $weaknesses;

    /** @var bool whether the password is strong enough */
    private $acceptable;

    /**
     * @param int $score
     * @param array $weaknesses
     * @param bool $acceptable
     */
    public function __construct(int $score, array $weaknesses, bool $acceptable)
    {
        $this->score = $score;
        $this->weaknesses = $weaknesses;
        $this->acceptable = $acceptable;
    }

    /**
     * @return bool Whether the password is acceptable to use
     */
    public function isAcceptable(): bool
    {
        return $this->acceptable;
    }

    /**
     * @return int
     */
    public function getScore(): int
    {
        return $this->score;
    }

    /**
     * @return string[]
     */
    public function getWeaknesses(): array
    {
        return $this->weaknesses;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'weaknesses' => $this->weaknesses,
            'acceptable' => $this->acceptable
        ];
    }
}
