<?php

namespace Datto\Https;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * Represents the renewal status of a certificate.
 *
 * @author Philipp Heckel <ph@datto.com>
 *
 * @method static RenewalStatus NOT_NEEDED()
 * @method static RenewalStatus NEEDED_NOW()
 * @method static RenewalStatus NEEDED_SOON()
 */
class RenewalStatus extends AbstractEnumeration
{
    const NOT_NEEDED = 'not-needed';
    const NEEDED_NOW = 'needed-now';
    const NEEDED_SOON = 'needed-soon';
}
