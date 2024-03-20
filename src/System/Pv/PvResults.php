<?php

namespace Datto\System\Pv;

use Datto\System\MonitorableProcessResults;

/**
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class PvResults extends MonitorableProcessResults
{
    const EXIT_CODE_PID_RID_FAILURE = 1;
    const EXIT_CODE_CANNOT_OPEN = 2;
    const EXIT_CODE_SAME_INPUT_OUTPUT = 4;
    const EXIT_CODE_FAILURE_TO_CLOSE_FILE = 8;
    const EXIT_CODE_DATA_TRANSFER_ERROR = 16;
    const EXIT_CODE_SIGNAL_CAUGHT = 32;
    const EXIT_CODE_MALLOC_ERROR = 64;

    /**
     * Get the message associated with the exit code of these results. Source: 'man pv' for version 1.6.0-1 See
     * also http://www.ivarch.com/programs/quickref/pv.shtml
     *
     * @return string The message associated with the exit code of these results.
     */
    public function getExitCodeText(): string
    {
        switch ($this->exitCode) {
            case self::EXIT_CODE_SUCCESS:
                return 'Success';
            case self::EXIT_CODE_PID_RID_FAILURE:
                return 'Problem with the -R or -P options';
            case self::EXIT_CODE_CANNOT_OPEN:
                return 'One or more files could not be accessed, stat(2)ed, or opened';
            case self::EXIT_CODE_SAME_INPUT_OUTPUT:
                return 'An input file was the same as the output file';
            case self::EXIT_CODE_FAILURE_TO_CLOSE_FILE:
                return 'Internal error with closing a file or moving to the next file';
            case self::EXIT_CODE_DATA_TRANSFER_ERROR:
                return 'There was an error while transferring data from one or more input files';
            case self::EXIT_CODE_SIGNAL_CAUGHT:
                return 'A signal was caught that caused an early exit';
            case self::EXIT_CODE_MALLOC_ERROR:
                return 'Memory allocation failed';
            default:
                return 'Undefined exit code';
        }
    }
}
