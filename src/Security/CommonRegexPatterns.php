<?php

namespace Datto\Security;

/**
 * Class CommonRegexPatterns contains PHP regular expression pattern constants only
 *
 * https://regex101.com is useful for composing/testing your patterns. Ensure your 'test strings' do not
 * divulge any internal company information when using such a site. (example: an internal URL, ip, etc...)
 * When a standard exists, use that standard as the basis of your pattern composition. Don't forget
 * about length checks.
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class CommonRegexPatterns
{
    /**
     * 1-24 letters or numbers plus hyphen, cannot start with digit or hyphen, and may not end with a hyphen
     * Reference: http://www.ietf.org/rfc/rfc952.txt
     * @const HOSTNAME_RFC_952
     */
    const HOSTNAME_RFC_952 = '/^[a-zA-Z][-a-zA-Z0-9]{0,23}$(?<!-)/';

    /**
     * 1-63 letters or numbers plus hyphen, cannot start with a hyphen, and may not end with a hyphen
     * Reference: http://www.ietf.org/rfc/rfc1123.txt
     * @const HOSTNAME_RFC_1123
     */
    const HOSTNAME_RFC_1123 = '/^[a-zA-Z0-9][-a-zA-Z0-9]{0,62}$(?<!-)/';

    /**
     * Validates an IETF RFC-1035 Domain Name
     *
     * @see https://stackoverflow.com/a/16491074
     * @example https://regex101.com/r/IY4AVw/1
     *
     *  - Each label/level (split by a dot) may contain up to 63 characters.
     *  - The full domain name may have up to 127 levels.
     *  - The full domain name may not exceed the length of 253 characters in its textual representation.
     *  - Each label can consist of letters, digits and hyphens.
     *  - Labels cannot start or end with a hyphen.
     *  - The top-level domain (extension) cannot be all-numeric.
     */
    const DOMAIN_NAME_RFC_1035 = '/^(?!\-)(?:(?:[a-zA-Z\d][a-zA-Z\d\-]{0,61})?[a-zA-Z\d]\.){1,126}(?!\d+)[a-zA-Z\d]{1,63}$/';
}
