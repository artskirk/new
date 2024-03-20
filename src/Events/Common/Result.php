<?php

namespace Datto\Events\Common;

use Datto\Events\AbstractEventNode;

/**
 * A general-use event node for describing the outcome of some work that was done
 *
 * Since results contain error messages, which may vary significantly, they should only be used within the context node.
 */
class Result extends AbstractEventNode
{
    use RemoveNullProperties;

    /** @var bool TRUE if this result represents success */
    protected $success;

    /** @var string[]|null any errors that occurred while doing the work that this result is about */
    protected $errors = null;

    public function __construct(bool $success, array $errors = null)
    {
        $this->success = $success;
        $this->errors = $errors;
    }
}
