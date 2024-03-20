<?php

namespace Datto\App\Security\Constraints;

use Datto\Asset\AssetException;
use Datto\Asset\AssetService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates that the symfony argument is an existing asset. This can be constrained further by specifying the
 * AssetType 'type' as a constraint parameter.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AssetExistsValidator extends ConstraintValidator
{
    /** @var AssetService */
    private $assetService;

    /**
     * AssetExistsValidator constructor.
     * @param AssetService|null $assetService
     */
    public function __construct(AssetService $assetService = null)
    {
        $this->assetService = $assetService ?: new AssetService();
    }

    /**
     * @inheritdoc
     */
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof AssetExists) {
            throw new UnexpectedTypeException($constraint, __NAMESPACE__.'\AssetExists');
        }

        $exists = is_string($value) && $this->assetService->exists($value);
        $acceptAny = $constraint->type === AssetExists::ANY_TYPE;

        try {
            $typeIsValid = ($acceptAny || $this->assetService->get($value)->isType($constraint->type));
        } catch (AssetException $assetException) {
            // assetService->get didn't find a value.
            $typeIsValid = false;
        }

        if ($exists && $typeIsValid) {
            return;
        }

        $this->context->addViolation($constraint->message, array('%string%' => $value, '%type%' => $constraint->type));
    }
}
