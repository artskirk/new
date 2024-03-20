<?php

namespace Datto\System\Rsync;

use Datto\System\MonitorableProcessResults;

/**
 * Class RsyncResults Represents the results of a MonitorableRsyncProcess
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class RsyncResults extends MonitorableProcessResults
{
    const EXIT_CODE_SYNTAX_ERROR = 1;
    const EXIT_CODE_PROTOCOL_INCOMPATIBLE = 2;
    const EXIT_CODE_ERRORS_SELECTING_FILES = 3;
    const EXIT_CODE_REQUESTED_ACTION_NOT_SUPPORTED = 4;
    const EXIT_CODE_ERROR_STARTING_CLIENT_SERVER = 5;
    const EXIT_CODE_DAEMON_UNABLE_TO_APPEND_LOGFILE = 6;
    const EXIT_CODE_ERROR_IN_SOCKET_IO = 10;
    const EXIT_CODE_ERROR_IN_FILE_IO = 11;
    const EXIT_CODE_ERROR_IN_PROTOCOL_DATASTREAM = 12;
    const EXIT_CODE_ERRORS_WITH_DIAGNOSTICS = 13;
    const EXIT_CODE_ERROR_IN_IPC_CODE = 14;
    const EXIT_CODE_RECEIVED_SIGUSR1_OR_SIGINT = 20;
    const EXIT_CODE_WAITPID_ERROR = 21;
    const EXIT_CODE_ERROR_ALLOCATING_MEMORY_BUFFERS = 22;
    const EXIT_CODE_PARTIAL_TRANSFER_ERROR = 23;
    const EXIT_CODE_PARTIAL_TRANSFER_VANISHED_FILES = 24;
    const EXIT_CODE_MAX_DELETE_LIMIT_STOPPED_DELETIONS = 25;
    const EXIT_CODE_TIMEOUT_IN_SEND_RECEIVE = 30;
    const EXIT_CODE_TIMEOUT_WAITING_FOR_DAEMON = 35;
    const EXIT_CODE_TIMEOUT_WAITING_SYNC = 124;

    /** @var string */
    private $statsOutput;

    /**
     * @param int $exitCode The exit code of the rsync process
     * @param string $statsOutput The stats output of the rsync process
     * @param array $errorOutput The $error output by the rsync process
     */
    public function __construct(
        $exitCode,
        $statsOutput = '',
        $errorOutput = []
    ) {
        parent::__construct($exitCode, $errorOutput);

        $this->statsOutput = $statsOutput;
    }

    /**
     * @return string The stats output by the rsync process
     */
    public function getStatsOutput()
    {
        return $this->statsOutput;
    }

    /**
     * Get the message associated with the exit code of these results. Source: 'man rsync' for version 3.1.1. See
     * also https://linux.die.net/man/1/rsync
     *
     * @return string The message associated with the exit code of these results.
     */
    public function getExitCodeText()
    {
        switch ($this->exitCode) {
            case self::EXIT_CODE_SUCCESS:
                return "Success";
            case self::EXIT_CODE_SYNTAX_ERROR:
                return "Syntax or usage error";
            case self::EXIT_CODE_PROTOCOL_INCOMPATIBLE:
                return "Protocol incompatibility";
            case self::EXIT_CODE_ERRORS_SELECTING_FILES:
                return "Errors selecting input/output files, dirs";
            case self::EXIT_CODE_REQUESTED_ACTION_NOT_SUPPORTED:
                return "Requested action not supported: an attempt was made to manipulate 64-bit files on a platform "
                . "that cannot support them; or an option was specified that is supported by the client and "
                . "not by the server.";
            case self::EXIT_CODE_ERROR_STARTING_CLIENT_SERVER:
                return "Error starting client-server protocol";
            case self::EXIT_CODE_DAEMON_UNABLE_TO_APPEND_LOGFILE:
                return "Daemon unable to append to log-file";
            case self::EXIT_CODE_ERROR_IN_SOCKET_IO:
                return "Error in socket I/O";
            case self::EXIT_CODE_ERROR_IN_FILE_IO:
                return "Error in file I/O";
            case self::EXIT_CODE_ERROR_IN_PROTOCOL_DATASTREAM:
                return "Error in rsync protocol data stream";
            case self::EXIT_CODE_ERRORS_WITH_DIAGNOSTICS:
                return "Errors with program diagnostics";
            case self::EXIT_CODE_ERROR_IN_IPC_CODE:
                return "Error in IPC code";
            case self::EXIT_CODE_RECEIVED_SIGUSR1_OR_SIGINT:
                return "Received SIGUSR1 or SIGINT";
            case self::EXIT_CODE_WAITPID_ERROR:
                return "Some error returned by waitpid()";
            case self::EXIT_CODE_ERROR_ALLOCATING_MEMORY_BUFFERS:
                return "Error allocating core memory buffers";
            case self::EXIT_CODE_PARTIAL_TRANSFER_ERROR:
                return "Partial transfer due to error";
            case self::EXIT_CODE_PARTIAL_TRANSFER_VANISHED_FILES:
                return "Partial transfer due to vanished source files";
            case self::EXIT_CODE_MAX_DELETE_LIMIT_STOPPED_DELETIONS:
                return "The --max-delete limit stopped deletions";
            case self::EXIT_CODE_TIMEOUT_IN_SEND_RECEIVE:
                return "Timeout in data send/receive";
            case self::EXIT_CODE_TIMEOUT_WAITING_FOR_DAEMON:
                return "Timeout waiting for daemon connection";
            case self::EXIT_CODE_TIMEOUT_WAITING_SYNC:
                return "The data could not be synced after transfer.  Please retry.";
            default:
                return "Undefined exit code";
        }
    }
}
