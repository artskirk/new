<?php

namespace Datto\Winexe\Exception;

use Exception;

/**
 * An exception that should be thrown whenever invalid credentials were passed
 * to winexe binary.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class InvalidLoginException extends Exception
{
}
