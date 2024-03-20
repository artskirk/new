<?php

namespace Datto\Util;

/**
 * Utility class to modify strings.
 *
 * @package Datto\Util
 */
class StringUtil
{
    /**
     * Generate a random, globally unique identifier.
     *
     * @return string
     */
    public static function generateGuid()
    {
        $data = file_get_contents('/dev/urandom', null, null, 0, 16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Separates an argument string into an array of arguments
     *
     * @param string $arguments
     * @return array
     */
    public static function splitArguments(string $arguments): array
    {
        // gracefully handle multiple spaces eg. 'fsutil    dirty query C:'
        $arguments = trim($arguments);
        $arguments = preg_replace('/\s+/', ' ', $arguments);
        // str_getcsv correctly handles escape characters (like quotations), preg_split and explode do not
        return $arguments ? str_getcsv($arguments, ' ') : [];
    }

    /**
     * Separates a string by newlines into a list of strings
     *
     * @param string @string the string to split
     * @return array a list array
     */
    public static function splitByNewline(string $string): array
    {
        return preg_split('/\R/', $string);
    }

    /**
     * Separates a string by all whitespace into a list of strings
     * 'Whitespace' includes spaces, tabs and newlines of any length
     *
     * @param string @string the string to split
     * @return array
     */
    public static function splitByWhitespace(string $string): array
    {
        return preg_split('/\s+/', $string);
    }

    /**
     * Finds all unsigned integers in a string and returns an array of integers
     *
     * @param string $string
     * @return int[]
     */
    public static function extractIntegers(string $string): array
    {
        preg_match_all('/\d+/', $string, $matches);
        return array_map('intval', $matches[0]);
    }
}
