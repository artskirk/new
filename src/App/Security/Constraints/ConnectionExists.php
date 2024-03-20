<?php

namespace Datto\App\Security\Constraints;

use Datto\Connection\ConnectionType;
use Symfony\Component\Validator\Constraint;

/**
 * This constraint makes sure that the value corresponds to a hypervisor connection that exists and is valid.
 *
 * @Annotation
 * The Annotation annotation is necessary for this new constraint in order to make it available
 * for use in classes via annotations.
 *
 * @author Shawn Carpenter <scarpenter@datto.com>
 */
class ConnectionExists extends Constraint
{
    // Example Usage:
    //    @Datto\JsonRpc\Validator\Validate(fields={
    //         "name" = @Datto\App\Security\Constraints\ConnectionExists()
    //     })

    const ANY_TYPE = null;

    /** @var ConnectionType|null */
    public $type = self::ANY_TYPE;
    public $message = '"%string%" is not a valid connection.';

    public function __construct($options)
    {
        parent::__construct($options);

        if (isset($options['type']) && is_string($options['type'])) {
            $this->type = ConnectionType::memberByValue($options['type']);
        }
    }
}
