<?php

namespace Datto\App\Console\Command;

use Symfony\Component\Validator\Validation;

class CommandValidator
{
    protected $validator;

    public function __construct()
    {
        $this->validator = Validation::createValidatorBuilder()->getValidator();
    }

    /**
     * @param $value
     * @param mixed $constraint one of the constraints found in Symfony\Component\Validator\Constraints
     * @param string $message
     */
    public function validateValue($value, $constraint, $message = ''): void
    {
        $violations = $this->validator->validate($value, $constraint);
        if (count($violations) > 0) {
            if (!empty($message)) {
                throw new \InvalidArgumentException($message);
            } else {
                throw new \InvalidArgumentException('Error with "'.$violations[0]->getInvalidValue().'". '.$violations[0]->getMessage());
            }
        }
    }
}
