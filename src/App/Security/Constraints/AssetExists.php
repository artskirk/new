<?php

namespace Datto\App\Security\Constraints;

use Datto\Asset\AssetType;
use Symfony\Component\Validator\Constraint;

/**
 * This constraint makes sure the value corresponds to an existing asset on the device.
 * It takes an optional 'type' parameter which can restrict the asset to a specific type.
 * Valid types come from AssetType.
 *
 * This ensures the "hostname" parameter of an api method corresponds to an existing agent on the device.
 * Note that 'agent' is passed instead of AssetType::AGENT since constants can't be used with comment based validation.
 *
 *
 * @Annotation
 * The Annotation annotation is necessary for this new constraint in order to make it available
 * for use in classes via annotations.
 */
class AssetExists extends Constraint
{
    // Example Usage:
    //    @Datto\JsonRpc\Validator\Validate(fields={
    //         "hostname" = @Datto\App\Security\Constraints\AssetExists(type="agent")
    //     })

    const ANY_TYPE = '';

    /** @var string */
    public $type = self::ANY_TYPE;
    public $message = '"%string%" is not a valid asset(%type%).';

    /**
     * @param array $options
     */
    public function __construct($options)
    {
        parent::__construct($options);

        if (isset($options['type']) && is_string($options['type'])) {
            AssetType::toClassName($options['type']); // throws exception if type doesn't exist
            $this->type = $options['type'];
        }
    }
}
