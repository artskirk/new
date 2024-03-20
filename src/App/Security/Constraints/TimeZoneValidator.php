<?php

namespace Datto\App\Security\Constraints;

use DateTimeZone;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * This makes sure the field is one of the values returned by DateTimeZone::listIdentifiers()
 * @author Matt Cheman <mcheman@datto.com>
 */
class TimeZoneValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof TimeZone) {
            throw new UnexpectedTypeException($constraint, __NAMESPACE__.'\TimeZone');
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!in_array($value, DateTimeZone::listIdentifiers(), true)) {
            $this->context->addViolation($constraint->message, array('%string%' => $value));
        }
    }
}
