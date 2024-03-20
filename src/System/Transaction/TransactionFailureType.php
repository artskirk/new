<?php

namespace Datto\System\Transaction;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * An enumeration of verification result types
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 *
 * @method static TransactionFailureType STOP_ON_FAILURE()
 * @method static TransactionFailureType CONTINUE_ON_FAILURE()
 */
class TransactionFailureType extends AbstractEnumeration
{
    const STOP_ON_FAILURE = 0;
    const CONTINUE_ON_FAILURE = 1;
}
