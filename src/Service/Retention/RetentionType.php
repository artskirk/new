<?php

namespace Datto\Service\Retention;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * Represent the type of retention.
 *
 * @method static RetentionType OFFSITE()
 * @method static RetentionType LOCAL()
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class RetentionType extends AbstractEnumeration
{
    const OFFSITE = 'offsite';
    const LOCAL = 'local';
}
