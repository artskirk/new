<?php

namespace Datto\Verification;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * An enumeration of verification result types
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Matt Coleman <mcoleman@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 *
 * @method static VerificationResultType SUCCESS()
 * @method static VerificationResultType FAILURE_UNRECOVERABLE()
 * @method static VerificationResultType FAILURE_INTERMITTENT()
 * @method static VerificationResultType SKIPPED()
 */
class VerificationResultType extends AbstractEnumeration
{
    const SUCCESS = 0;
    const FAILURE_INTERMITTENT = 1;
    const FAILURE_UNRECOVERABLE = 2;
    const SKIPPED = 3;
}
