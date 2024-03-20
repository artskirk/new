<?php

namespace Datto\App\Security\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * This constraint makes sure the value corresponds to an existing dataset on the device.
 *
 * This is for cases when we are dealing with ZFS directly and not in the context of
 * an existing asset.
 *
 * @Annotation
 * The Annotation annotation is necessary for this new constraint in order to make it available
 * for use in classes via annotations.
 */
class DatasetExists extends Constraint
{
    // Example Usage:
    //    @Datto\JsonRpc\Validator\Validate(fields={
    //         "hostname" = @Datto\App\Security\Constraints\DatasetExists()
    //     })

    /** @var string */
    public $message = '"%string%" is not a valid dataset.';
}
