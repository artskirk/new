<?php

namespace Datto\Security;

/**
 * Secure password generator.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class PasswordGenerator
{
    const POSSIBLE_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * Generates a password with a specific length.
     *
     * After discovering that returning other printable characters breaks ESX.
     * The passwords generated are alphanumeric only.
     *
     * @param int $length the number of characters in the password
     * @return string the generated password
     */
    public static function generate($length)
    {
        $password = '';
        $max = strlen(static::POSSIBLE_CHARACTERS) - 1;
        for ($i = 0; $i < $length; $i++) {
            $password .= substr(static::POSSIBLE_CHARACTERS, random_int(0, $max), 1);
        }
        return $password;
    }
}
