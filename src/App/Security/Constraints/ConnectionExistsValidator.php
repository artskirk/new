<?php

namespace Datto\App\Security\Constraints;

use Datto\Connection\Service\ConnectionService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates that the symfony argument is an existing hypervisor connection
 *
 * @author Shawn Carpenter <scarpenter@datto.com>
 */
class ConnectionExistsValidator extends ConstraintValidator
{
    /** @var ConnectionService */
    private $connectionService;

    /**
     * @param ConnectionService|null $connectionService
     */
    public function __construct(ConnectionService $connectionService = null)
    {
        $this->connectionService = $connectionService ?: new ConnectionService();
    }

    /**
     * Checks if the passed value is valid.
     *
     * @param mixed $value The value that should be validated
     * @param Constraint $constraint The constraint for the validation
     */
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof ConnectionExists) {
            throw new UnexpectedTypeException($constraint, ConnectionExists::class);
        }

        $connection = $this->connectionService->get($value);
        $exists = $connection !== null;
        $acceptAny = $constraint->type === ConnectionExists::ANY_TYPE;

        if ($exists) {
            $typeIsValid = ($acceptAny || $connection->getType() === $constraint->type);
        } else {
            $typeIsValid = false;
        }

        if ($exists && $typeIsValid) {
            return;
        }

        $this->context->addViolation($constraint->message, array('%string%' => $value, '%type%' => $constraint->type));
    }
}
