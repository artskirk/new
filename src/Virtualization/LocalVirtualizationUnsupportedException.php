<?php

namespace Datto\Virtualization;

/**
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class LocalVirtualizationUnsupportedException extends \Exception
{
    public const MESSAGE_PREFIX = 'Local virtualization unsupported.';

    /**
     * @param string|null $message Additional information such as a corrective action.
     * @param int $code default 0
     * @param \Exception|null $previous
     */
    public function __construct($message = null, $code = 0, \Exception $previous = null)
    {
        $detailedMessage = self::MESSAGE_PREFIX;
        if (!is_null($message)) {
            $detailedMessage .= ' ' . $message;
        }
        parent::__construct($detailedMessage, $code, $previous);
    }
}
