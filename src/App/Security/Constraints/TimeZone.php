<?php

namespace Datto\App\Security\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class TimeZone extends Constraint
{
    public $message = 'The string "%string%" is not a valid time zone.';
}
