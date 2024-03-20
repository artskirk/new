<?php

namespace Datto\Asset;

use Datto\Util\StringUtil;
use Symfony\Component\Validator\Constraints\Uuid;
use Symfony\Component\Validator\Validation;

/**
 * Generates Asset UUIDs.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class UuidGenerator
{
    /**
     * Get a UUID for a new (or existing, UUID-less) asset.
     *
     * @return string
     */
    public function get(bool $includeHyphens = false)
    {
        $uuidWithHyphens = StringUtil::generateGuid();
        if (!$includeHyphens) {
            return str_replace('-', '', $uuidWithHyphens);
        }
        return $uuidWithHyphens;
    }

    /**
     * Verify that a string represents a UUID.
     *
     * @param string|null $uuid the string to test.
     * @param bool $strict whether the format must exactly match 8-4-4-4-12
     * @return bool
     */
    public static function isUuid(?string $uuid, bool $strict = false): bool
    {
        if (empty($uuid)) {
            return false;
        }

        $validator = Validation::createValidator();

        // use strict=false to allow validation against a string that doesn't contain hyphens
        $uuidConstraint = new Uuid(['strict' => $strict]);
        $errors = $validator->validate($uuid, $uuidConstraint);

        // validator will not report any errors if argument is empty string or null
        return $errors->count() === 0;
    }

    /**
     * Strips extra characters from and restores hyphens to a "UUID"
     *
     * Returns an empty string if fewer than 32 hex characters are found.
     *
     * Note: Not all combinations of 32 hex characters are valid as UUIDs.  This method does not guarantee that the
     * characters in the output represent a valid UUID.
     *
     * @param string $uuid
     * @return string An 8-4-4-4-12 formatting of the first 32 hex characters found or an empty string.
     */
    public static function reformat(string $uuid): string
    {
        $cleaned = preg_replace('/[^[:xdigit:]]+/', '', $uuid);
        $segmentLengths = [8, 4, 4, 4, 12];
        if (strlen($cleaned) < array_sum($segmentLengths)) {
            return '';
        }
        $offset = 0;
        $resultSegments = [];
        foreach ($segmentLengths as $segmentLength) {
            $resultSegments[] = substr($cleaned, $offset, $segmentLength);
            $offset += $segmentLength;
        }
        return implode('-', $resultSegments);
    }
}
