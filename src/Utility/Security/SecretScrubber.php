<?php

namespace Datto\Utility\Security;

/**
 * Utility to Remove secret words from string.
 *
 * @author Vipin  Saini <vsaini@datto.com>
 */
class SecretScrubber
{
    /**
     * Remove secret words from string
     *
     * @param array $secretStrings Secret to be removed
     * @param string $input Input String
     * @param int $maxLength optionally truncate search strings over this length
     * @param string $maxLengthSuffix optionally append this string to search string when its truncated
     * @return string
     */
    public function scrubSecrets(
        array $secretStrings,
        string $input,
        int $maxLength = 0,
        string $maxLengthSuffix = ''
    ): string {
        foreach ($secretStrings as $word) {
            if (!empty($word)) {
                if ($maxLength !== 0 && strlen($word) > $maxLength) {
                    $truncatedWord = substr($word, 0, $maxLength) . $maxLengthSuffix;
                    $input = str_replace($truncatedWord, '***', $input);
                }
                $input = str_replace($word, '***', $input);
            }
        }

        return $input;
    }
}
