<?php

namespace Datto\Utility;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\Sleep;
use Datto\Log\DeviceLoggerInterface;

/**
 * Wrapper for screen
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 * @author Afeique Sheikh <asheikh@datto.com>
 */
class Screen
{
    const SCREEN_BINARY = 'screen';
    const SYSTEMD_RUN = 'systemd-run';
    const TIMEOUT = 604800; // one week
    const KILL_POLL_SLEEP = 2;

    /**
     * @var int Calculated from the maximum socket length (108) - path prefix (19) - process ID (5) - delimiter and
     * terminator (2) - extra headroom (3).  The headroom brings us in line with SpeedSync's value.
     */
    const MAXIMUM_SCREEN_NAME_LENGTH = 79;
    const DETAILS_OMISSION_STRING = '---';

    /** @var ProcessFactory */
    private $processFactory;

    /** @var PosixHelper */
    private $posixHelper;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Sleep */
    private $sleep;

    public function __construct(
        DeviceLoggerInterface $logger,
        ProcessFactory $processFactory = null,
        PosixHelper $posixHelper = null,
        Sleep $sleep = null
    ) {
        $this->logger = $logger;
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->posixHelper = $posixHelper ?: new PosixHelper($this->processFactory);
        $this->sleep = $sleep ?: new Sleep();
    }

    /**
     * Run given command in a back-grounded screen
     *
     * @param string[] $command
     * @param string $screenHash
     * @param bool $viaSystemd (optional) if set to true, screen will be
     *  started via systemd-run.
     *
     * @return bool
     */
    public function runInBackground(
        array $command,
        string $screenHash,
        bool $viaSystemd = false
    ): bool {
        $shortScreenHash = $this->getShortScreenName($screenHash);

        if ($viaSystemd) {
            $cmd = [
                static::SYSTEMD_RUN,
                '--scope',
                '--unit=' . $shortScreenHash,
                static::SCREEN_BINARY
            ];
        } else {
            $cmd = [static::SCREEN_BINARY];
        }

        $cmd = array_merge($cmd, [
            '-dmS',
            $shortScreenHash
        ]);

        $cmd = array_merge($cmd, $command);

        $process = $this->processFactory->get($cmd)
            ->setTimeout(static::TIMEOUT);

        $logContext = ['commandLine' => $process->getCommandLine()];
        $this->logger->debug('SCN0002 Starting screen.', $logContext);
        $process->run();

        if (!$process->isSuccessful()) {
            $logContext['output'] = $process->getOutput();
            $logContext['errorOutput'] = $process->getErrorOutput();
            $this->logger->error('SCN0000 Error launching screen.', $logContext);

            return false;
        }

        return true;
    }

    /**
     * Runs the given command in a backgrounded screen and prepends 'bash -c' to facilitate piping and redirects.
     *
     * YOU MUST ESCAPE YOUR PARAMETERS MANUALLY WHEN USING THIS METHOD
     *
     * This should only be used when using pipes or redirects. The preferred method is to call
     * 'runInBackground' with an array of arguments.
     */
    public function runInBackgroundWithoutEscaping(string $command, string $screenHash, bool $viaSystemd = false)
    {
        $commandArray = ['/bin/bash', '-c', $command];
        return $this->runInBackground($commandArray, $screenHash, $viaSystemd);
    }

    /**
     * Determine if screen with the given name is running
     *
     * @param string $screenHash
     * @return bool Whether or not the screen is running
     */
    public function isScreenRunning(string $screenHash): bool
    {
        $pid = $this->getScreenPid($screenHash);

        return $pid > 0;
    }

    /**
     * Kills running screen
     *
     * @param string $screenHash The name of the screen
     * @param int $signal The signal to send to the process
     * @param int $waitForExitTimeout If non zero, don't return until screen process has exited or this many seconds
     *                                have passed
     * @param bool $signalChildren when true, instead of signaling screen process, signal its children
     * @return bool Whether or not the screen was successfully killed
     */
    public function killScreen(
        string $screenHash,
        int $signal = PosixHelper::SIGNAL_KILL,
        int $waitForExitTimeout = 0,
        bool $signalChildren = false
    ): bool {
        if ($this->isScreenRunning($screenHash)) {
            // Screen is running, get pid, send signal to it, then screen -wipe $refName
            $screenPid = $this->getScreenPid($screenHash);

            //bail out if PIDs are <= 1
            if ($screenPid <= 1) {
                return false;
            }

            $pidsToSignal = [$screenPid];
            if ($signalChildren) {
                $bashPid = $this->posixHelper->getChildProcessIds($screenPid)[0] ?? 0;
                $pidsToSignal = $this->posixHelper->getChildProcessIds($bashPid);
            }

            //bail out if nothing to signal
            if (empty($pidsToSignal)) {
                return false;
            }

            $this->signalPids($pidsToSignal, $signal);
            $this->sleep->usleep(100);

            //If $pollTimeout is positive, poll till the screen process is gone or we give up
            // note: screen process will exit if all children exit
            while ($waitForExitTimeout > 0 && $this->isScreenRunning($screenHash)) {
                $this->sleep->sleep(self::KILL_POLL_SLEEP);
                $waitForExitTimeout -= self::KILL_POLL_SLEEP;
            }

            // The screen version installed on datto devices (v4.03.01) contains a bug where
            //   screen names starting with '0' will not be wiped through `screen -wipe $screenHash`.
            // See https://kaseya.atlassian.net/browse/BCDR-13459 for more details.
            $this->processFactory->get([static::SCREEN_BINARY, '-wipe'])
                ->run();

            $this->sleep->usleep(100);
        }

        return !$this->isScreenRunning($screenHash);
    }

    /**
     * Get a list of screen names that are running and match a partial name.
     *
     * @param string $partialName Partial screen name to match
     * @return string[] Screen names containing $screenHash with keys being the screen PID
     */
    public function getScreens(string $partialName): array
    {
        // The screen version installed on datto devices (v4.03.01) contains a bug where
        //   screen names starting with '0' will not be returned through `screen -ls $screenHash`.
        // See https://kaseya.atlassian.net/browse/BCDR-13459 for more details.
        $process = $this->processFactory->get([static::SCREEN_BINARY, '-ls']);

        $process->run();
        $output = $process->getOutput();

        if (!$process->isSuccessful() && strpos($output, 'No Sockets found') !== 0) {
            $this->logger->warning(
                'SCN0003 Screen -ls did not run successfully!',
                ['errorOutput' => $process->getErrorOutput()]
            );
        }

        $truncatedPartialName = substr($partialName, 0, $this->getTruncatedScreenNameSideLength());

        $screens = [];
        $outputLines = explode("\n", $output);

        /*
         * Example output:
         * There are screens on:
         *   22382.startBackup-10.0.23.73   (03/29/2017 10:18:01 AM)    (Detached)
         *   20438.speedsyncReportActions   (03/29/2017 10:16:07 AM)    (Detached)
         *   15453.usbBmrListen (02/21/2017 11:56:13 AM)    (Detached)
         *   5923.heartbeat (02/21/2017 11:46:39 AM)    (Detached)
         * 4 Sockets in /var/run/screen/S-root.
         */
        foreach ($outputLines as $line) {
            // `screen -ls $partialName` matches only if the partial is the start of the name. Do the same here.
            if (preg_match('/^\s*(\d+)\.(\S+)/', $line, $matches) &&
                ($partialName === '' || strpos($matches[2], $truncatedPartialName) === 0)) {
                $pid = intval($matches[1]);
                $screenName = $matches[2];
                $screens[$pid] = $screenName;
            }
        }

        return $screens;
    }

    /**
     * Truncate a screen name to fit under the operating system limit.
     *
     * The version of screen that ships with Ubuntu 20.04 uses sockets, and sockets have a limit on total path length
     * of 108.  We must account for other details screen will append, like the base path and the process ID.
     *
     * Note: this method preserves the beginning and the end of the original name to be consistent with the SpeedSync
     * implementation of the same limit.
     *
     * @param string $screenName the original screen name
     * @return string the original screen name if short, a truncated version if long.
     */
    public function getShortScreenName(string $screenName): string
    {
        if (strlen($screenName) <= self::MAXIMUM_SCREEN_NAME_LENGTH) {
            return $screenName;
        }

        $sideLength = $this->getTruncatedScreenNameSideLength();
        $leadingSide = substr($screenName, 0, $sideLength);
        $trailingSide = substr($screenName, -1 * $sideLength);
        return $leadingSide . self::DETAILS_OMISSION_STRING . $trailingSide;
    }

    /**
     * Get the PID of a running screen.
     *
     * @param string $screenHash Screen name
     * @return int $pid Positive integer of PID on success, 0 otherwise
     */
    private function getScreenPid(string $screenHash): int
    {
        $pid = 0;
        $screens = $this->getScreens($screenHash);

        if (count($screens) > 0) {
            end($screens);
            $pid = key($screens);
        }

        return $pid;
    }

    /**
     * Given an array of pids, and a signal, send that signal
     * to all the PIDs
     *
     * @param int[] $pids to signal
     * @param int $signal to send
     */
    private function signalPids(array $pids, int $signal)
    {
        foreach ($pids as $pid) {
            $this->posixHelper->kill($pid, $signal);
        }
    }

    /**
     * How long is the retained portion of a too-long screen name before (or after) the omitted portion?
     *
     * @return int
     */
    private function getTruncatedScreenNameSideLength(): int
    {
        return intdiv(self::MAXIMUM_SCREEN_NAME_LENGTH - strlen(self::DETAILS_OMISSION_STRING), 2);
    }
}
