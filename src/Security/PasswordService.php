<?php

namespace Datto\Security;

use Exception;
use ZxcvbnPhp\Matchers\Bruteforce;
use ZxcvbnPhp\Matchers\DateMatch;
use ZxcvbnPhp\Matchers\DictionaryMatch;
use ZxcvbnPhp\Matchers\DigitMatch;
use ZxcvbnPhp\Matchers\L33tMatch;
use ZxcvbnPhp\Matchers\Match;
use ZxcvbnPhp\Matchers\RepeatMatch;
use ZxcvbnPhp\Matchers\SequenceMatch;
use ZxcvbnPhp\Matchers\SpatialMatch;
use ZxcvbnPhp\Matchers\YearMatch;
use ZxcvbnPhp\Zxcvbn;

/**
 * Assesses password strength
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class PasswordService
{
    const MINIMUM_SCORE = 2;
    const MIN_PASSWORD_LENGTH = 8;
    const MAX_PASSWORD_LENGTH = 128;

    const SPECIFICALLY_ALLOWED_PASSWORD_HASHES = [
        '$2y$10$a635LkT7q5Jr.smJmdg/FuFUBXo4/TSm9tFgCfaJJEAJMHar35gfq'
    ];

    const FORBIDDEN_PASSWORDS = [
        'datto',
        'siris',
        'alto',
        'device',
        'partner'
    ];

    const WEAKNESS_COMMON = 'common';
    const WEAKNESS_DATE = 'date';
    const WEAKNESS_EASY = 'easy';
    const WEAKNESS_LONG = 'long';
    const WEAKNESS_REPEAT = 'repeating';
    const WEAKNESS_SHORT = 'short';
    const WEAKNESS_SEQUENCE = 'sequence';
    const WEAKNESS_SPATIAL = 'spatial';
    const WEAKNESS_USER = 'user';

    /** @var Zxcvbn */
    private $zxcvbn;

    /**
     * @param Zxcvbn $zxcvbn
     */
    public function __construct(Zxcvbn $zxcvbn)
    {
        $this->zxcvbn = $zxcvbn;
    }

    /**
     * Validate a password for minimum security requirements.
     *
     * @param string $username Will disallow passwords that contain the username
     * @param string $password
     */
    public function validatePassword(string $password, string $username)
    {
        $passwordStrength = $this->getPasswordStrength($username, $password);

        if (!$passwordStrength->isAcceptable()) {
            throw new Exception('Password is not sufficiently strong');
        }
    }

    /**
     * Given a password, gets a strength score for that password based on Zxcvbn and Datto standards.
     *
     * @param string $username Will score passwords lower when they contain the username
     * @param string $password
     */
    public function getPasswordStrength(string $username, string $password): PasswordStrength
    {
        // Allow specific passwords we need for testing
        foreach (self::SPECIFICALLY_ALLOWED_PASSWORD_HASHES as $hash) {
            if (password_verify($password, $hash)) {
                return new PasswordStrength(4, [], true);
            }
        }

        // Enforce Datto standards for passwords
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return new PasswordStrength(0, [self::WEAKNESS_SHORT], false);
        } elseif (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return new PasswordStrength(0, [self::WEAKNESS_LONG], false);
        }

        $forbiddenWords = self::FORBIDDEN_PASSWORDS;
        $forbiddenWords[] = $username;

        // Enforce common-sense rules for passwords (no easy-to-guess passwords)
        $passwordStrength = $this->zxcvbn->passwordStrength($password, $forbiddenWords);
        $weaknesses = $this->translateZxcvbnWeaknesses($passwordStrength['match_sequence'] ?? []);

        $score = $passwordStrength['score'] ?? 0;
        $isAcceptable = $score >= self::MINIMUM_SCORE;

        return new PasswordStrength($score, $weaknesses, $isAcceptable);
    }

    /**
     * @param Match[] $matches
     * @return string[]
     */
    private function translateZxcvbnWeaknesses(array $matches): array
    {
        $weaknesses = [];
        foreach ($matches as $match) {
            switch (get_class($match)) {
                case DateMatch::class:
                case YearMatch::class:
                    $weaknesses[] = self::WEAKNESS_DATE;
                    break;
                case DictionaryMatch::class:
                case L33tMatch::class:
                    if ($match->dictionaryName === 'passwords') {
                        $weaknesses[] = self::WEAKNESS_COMMON;
                    } elseif ($match->dictionaryName === 'user_inputs') {
                        $weaknesses[] = self::WEAKNESS_USER;
                    }
                    break;
                case RepeatMatch::class:
                    $weaknesses[] = self::WEAKNESS_REPEAT;
                    break;
                case SequenceMatch::class:
                    $weaknesses[] = self::WEAKNESS_SEQUENCE;
                    break;
                case SpatialMatch::class:
                    $weaknesses[] = self::WEAKNESS_SPATIAL;
                    break;
                case Bruteforce::class:
                case DigitMatch::class:
                default:
                    $weaknesses[] = self::WEAKNESS_EASY;
                    break;
            }
        }

        return array_values(array_unique($weaknesses));
    }
}
