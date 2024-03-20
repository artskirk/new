<?php

namespace Datto\Backup;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Api\AgentApiException;
use Throwable;

/**
 * Translates exceptions into user facing error codes and messages.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class TranslatableExceptionService
{
    /**
     * Translate an exception into a user facing error code and message.
     *
     * The full message returned by this function is not displayed directly to
     * the user.  The front end uses the user facing error code (e.g. "BK002")
     * to look up the appropriate translation message which is then displayed
     * to the user.  See translation keys "agents.block.alert.message.<code>".
     *
     * @param Throwable $exception The exception to translate.
     * @param AgentPlatform $platform
     * @param string $defaultMessage The default message to return if the
     *     exception doesn't correspond to one of the user facing messages.
     * @return string The user facing error code and message.
     */
    public function translateException(
        Throwable $exception,
        AgentPlatform $platform,
        string $defaultMessage
    ): string {
        $message = $defaultMessage . " (" . $exception->getMessage()  . ")." ;
        
        if ($exception instanceof AgentApiException) {
            switch ($exception->getHttpCode()) {
                case 400:
                    $messageText = 'Backup failed because of a problem with making a backup request to the agent';
                    switch ($platform) {
                        case AgentPlatform::SHADOWSNAP():
                            $message = "BK001 - $messageText";
                            break;
                        case AgentPlatform::DATTO_WINDOWS_AGENT():
                            $message = "BK101 - $messageText";
                            break;
                        case AgentPlatform::DATTO_LINUX_AGENT():
                            $message = "BK201 - $messageText";
                            break;
                        case AgentPlatform::DATTO_MAC_AGENT():
                            $message = "BK301 - $messageText";
                            break;
                    }
                    break;
                case 401:
                    $messageText = 'Backup failed due to a problem establishing secure communications with the agent';
                    switch ($platform) {
                        case AgentPlatform::SHADOWSNAP():
                            $message = "BK002 - $messageText";
                            break;
                        case AgentPlatform::DATTO_WINDOWS_AGENT():
                            $message = "BK102 - $messageText";
                            break;
                        case AgentPlatform::DATTO_LINUX_AGENT():
                            $message = "BK202 - $messageText";
                            break;
                        case AgentPlatform::DATTO_MAC_AGENT():
                            $message = "BK302 - $messageText";
                            break;
                    }
                    break;
                case 500:
                    $messageText = 'Backup failed because the agent was unable to initiate the backup job';
                    switch ($platform) {
                        case AgentPlatform::SHADOWSNAP():
                            $message = "BK003 - $messageText";
                            break;
                        case AgentPlatform::DATTO_WINDOWS_AGENT():
                            $message = "BK103 - $messageText";
                            break;
                        case AgentPlatform::DATTO_LINUX_AGENT():
                            $message = "BK203 - $messageText";
                            break;
                        case AgentPlatform::DATTO_MAC_AGENT():
                            $message = "BK303 - $messageText";
                            break;
                    }
                    break;
            }
        } elseif ($exception instanceof BackupException) {
            switch ($exception->getCode()) {
                case BackupException::STC_DATTO_IMAGE_NOT_FOUND:
                    $message = 'BK004 - Backup failed because the protected system was not able to access the backup image file over the network';
                    break;
                case BackupException::ERRNO_4_INTERRUPTED_FUNCTION:
                    $message = 'BK006 - Backup failed due to unknown error in the agent software';
                    break;
                case BackupException::ERRNO_13_PERMISSION_DENIED:
                    $message = 'BK007 - Backup failed due to unknown error in the agent software';
                    break;
                case BackupException::STC_FILE_NOT_FOUND:
                    $message = 'BK008 - Backup failed because the agent was unable to access a necessary file path on the protected system';
                    break;
                case BackupException::STC_NETWORK_COMMUNICATION:
                    $message = 'BK010 - Backup failed due to a process timeout on the protected system';
                    break;
                case BackupException::STC_INSUFFICIENT_RESOURCES:
                    $message = 'BK011 - Backup failed due to insufficient resources on the protected system';
                    break;
                case BackupException::STC_FINAL_ERROR:
                    $message = 'BK012 - Backup failed due to I/O error from the protected system during the backup process';
                    break;
                case BackupException::STC_READ_ERROR:
                    $message = 'BK013 - Backup failed due to I/O error on read operation from the protected system during the backup process';
                    break;
                case BackupException::STC_WRITE_ERROR:
                    $message = 'BK014 - Backup failed due to I/O error on write operation from the protected system during the backup process';
                    break;
                default:
                    if (in_array($exception->getCode(), BackupException::AGENT_ERRORS)) {
                        $message = 'AGENT' . $exception->getCode() . ' - ' . $exception->getMessage();
                    }
                    break;
            }
        }

        return $message;
    }
}
