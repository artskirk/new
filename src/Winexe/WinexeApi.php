<?php

namespace Datto\Winexe;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Winexe\Exception\InvalidLoginException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Exception;

/**
 * Executes the Linux winexe command. Winexe is a
 * psexec-like client for accessing windows from Linux.
 *
 * @author John Fury Christ <furychrist@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class WinexeApi
{
    /** Directory for authentication parameters */
    const AUTHENTICATION_DIRECTORY = "/dev/shm";

    /** File prefix for authentication parameters */
    const AUTHENTICATION_FILE_PREFIX = "winexe";

    /** Path to the winexe binary */
    const WINEXE_BINARY_PATH = '/usr/bin/winexe';

    /** Message that appears in the error message if the credentials are wrong */
    const WINEXE_ERROR_MESSAGE_LOGON_FAILURE = 'NT_STATUS_LOGON_FAILURE';

    /** Message that appears in the error message if the wrong hypervisor was selected */
    const WINEXE_ERROR_MESSAGE_OBJECT_NOT_FOUND = 'NT_STATUS_OBJECT_NAME_NOT_FOUND';

    /** Prefix for executing cmd commands */
    const CMD_PREFIX = 'cmd /c';

    /** Prefix for executing powershell commands */
    const POWERSHELL_PREFIX = 'powershell.exe -ExecutionPolicy Bypass -NoLogo -NonInteractive -NoProfile -WindowStyle Hidden -Command';

    /** How long to wait for RPC service to respond */
    const DEFAULT_TIMEOUT = 30;

    /** @var Winexe $winexe */
    private $winexe;

    private ProcessFactory $processFactory;

    /** @var Filesystem $filesystem */
    private $filesystem;

    /**
     * @param Winexe $winexe a data object holding all info needed to run winexe.
     * @param ProcessFactory $processFactory
     * @param Filesystem $filesystem
     */
    public function __construct(
        Winexe $winexe,
        ProcessFactory $processFactory = null,
        Filesystem $filesystem = null
    ) {
        $this->winexe = $winexe;
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem($this->processFactory);
    }

    /**
     * Exectues a PowerShell command and gets its output.
     *
     * @param string $command
     * @param int $timeout Optional, command timeout. Default 30s
     *
     * @return string
     */
    public function runPowerShellCommand($command, $timeout = self::DEFAULT_TIMEOUT)
    {
        return $this->execute(
            sprintf('%s "%s"', self::POWERSHELL_PREFIX, $command),
            $timeout
        );
    }

    /**
     * Executes a CLI comand and gets its output.
     *
     * @param string $command
     * @param int $timeout Optional, command timeout. Default 30s
     *
     * @return string
     */
    public function runCliCommand($command, $timeout = self::DEFAULT_TIMEOUT)
    {
        return $this->execute(
            sprintf('%s "%s"', self::CMD_PREFIX, $command),
            $timeout
        );
    }

    /**
     * Execute a command via winexe
     *
     * @param string $command
     * @param int $timeout
     *
     * @return string the output of the executed command
     */
    private function execute($command, $timeout)
    {
        try {
            $authFile = $this->getAuthFile();

            $process = $this->processFactory
                ->get([self::WINEXE_BINARY_PATH, '--ostype=2', "--authentication-file=$authFile", "//{$this->winexe->getRemoteHost()}", $command])
                ->setTimeout($timeout);

            $process->mustRun();
            @$this->filesystem->unlink($authFile);
        } catch (ProcessTimedOutException $ex) {
            if (isset($authFile)) {
                @$this->filesystem->unlink($authFile);
            }

            $message = 'The host system did not respond to RPC command.';

            throw new Exception($message);
        } catch (Exception $ex) {
            if (isset($authFile)) {
                @$this->filesystem->unlink($authFile);
            }
            $message = $ex->getMessage();

            $invalidCredentials = strpos($message, self::WINEXE_ERROR_MESSAGE_LOGON_FAILURE) !== false;
            $invalidHypervisor = strpos($message, self::WINEXE_ERROR_MESSAGE_OBJECT_NOT_FOUND) !== false;

            if ($invalidCredentials) {
                throw new InvalidLoginException('Invalid login credentials provided');
            } elseif ($invalidHypervisor) {
                // TODO: figure out WTH is this, invalid IP would throw ProcessTimeoutException...
                $message = 'Failed to execute command on host. Wrong hypervisor selected.';
            }

            throw new Exception($this->stripSensitiveInfo($message));
        }

        return $this->stripSensitiveInfo($process->getOutput() .  $process->getErrorOutput());
    }

    /**
     * Removes credential info from given text.
     *
     * @param string $text
     *
     * @return string
     */
    private function stripSensitiveInfo($text)
    {
        $ret = preg_replace(
            "/'--authentication-file=[^ ]+/",
            "",
            $text
        );

        return $ret !== null ? $ret : '';
    }

    /**
     * Gets the path to the file with credentials to pass to winexe binary.
     *
     * The file is first created and populated with authentication data.
     *
     * @return string
     */
    private function getAuthFile()
    {
        $path = $this->filesystem->tempName(
            self::AUTHENTICATION_DIRECTORY,
            self::AUTHENTICATION_FILE_PREFIX
        );

        if ($path === false) {
            throw new Exception('Failed to create temporary file.');
        }

        $user = $this->winexe->getUserName();
        $pass = $this->winexe->getPassword();

        if (empty($user) || empty($pass)) {
            throw new Exception(
                'Missing authentication info'
            );
        }

        $out = '';

        $domainName = $this->winexe->getDomainName();
        if (!empty($domainName)) {
            $out .= "domain=$domainName\n";
        }

        $out .= "username=$user\n";
        $out .= "password=$pass\n";

        $bytes = $this->filesystem->filePutContents($path, $out, LOCK_EX);

        // if 0 bytes or FALSE, either way invalid
        if (empty($bytes)) {
            throw new Exception(
                'Failed to write authentication data to file.'
            );
        }

        return $path;
    }
}
