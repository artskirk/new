<?php

namespace Datto\App\Security\Constraints;

use Datto\ZFS\ZfsService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates that the symfony argument is an existing dataset.
 *
 * @author Justin Giacobbi <justin@datto.com>
 */
class DatasetExistsValidator extends ConstraintValidator
{
    /** @var ZfsService */
    private $zfsService;

    /**
     * AssetExistsValidator constructor.
     * @param ZfsService|null $zfsService
     */
    public function __construct(ZfsService $zfsService = null)
    {
        $this->zfsService = $zfsService ?: new ZfsService();
    }

    /**
     * @inheritdoc
     */
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof DatasetExists) {
            throw new UnexpectedTypeException($constraint, __NAMESPACE__.'\DatasetExists');
        }

        if (!$this->zfsService->exists($value)) {
            $this->context->addViolation(
                $constraint->message,
                ['%string%' => $value]
            );
        }
    }
}
