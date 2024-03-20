<?php

namespace Datto\App\EventListener;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\SnapctlApplication;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Common\Resource\Sleep;
use Datto\Utility\File\LockFactory;
use Datto\Utility\File\LockInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens for and handles the global snapctl
 * command options defined in @see SnapctlApplication.
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class CommandListener implements EventSubscriberInterface
{
    /** @var DeviceConfig */
    private $deviceConfig;

    private ProcessFactory $processFactory;

    /** @var Sleep */
    private $sleep;

    /** @var LockFactory */
    private $lockFactory;

    public function __construct(
        DeviceConfig $deviceConfig,
        ProcessFactory $processFactory,
        Sleep $sleep,
        LockFactory $lockFactory
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->processFactory = $processFactory;
        $this->sleep = $sleep;
        $this->lockFactory = $lockFactory;
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onConsoleCommand', 1]
        ];
    }

    /**
     * Called whenever a console command is entered.
     *
     * @param ConsoleCommandEvent $event
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $input = $event->getInput();
        $isSnapctlCommand = $event->getCommand()->getApplication() instanceof SnapctlApplication;

        // All commands have this option
        if ($input->getOption('verbose')) {
            $this->handleVerboseOption();
        }

        if (!$isSnapctlCommand) {
            return;
        }

        // Only snapctl commands have these options
        if (!$this->multipleInstancesAllowed($event->getCommand())) {
            $this->handleSingleInstanceOnly($event);
        }
        if ($input->getOption(SnapctlApplication::BACKGROUND_OPTION_NAME)) {
            $this->handleBackgroundOption($event);
        }
        if ($input->getOption(SnapctlApplication::CRON_OPTION_NAME)) {
            $this->handleCronOption($event);
        }
        if ($input->getOption(SnapctlApplication::FUZZ_OPTION_NAME) &&
            $this->fuzzAllowed($event->getCommand())
        ) {
            $this->handleFuzzOption($event);
        }
    }

    /**
     * Bumps up php error reporting.
     */
    public function handleVerboseOption(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
    }

    /**
     * Re-runs the command in the background.
     *
     * @param ConsoleCommandEvent $event
     */
    public function handleBackgroundOption(ConsoleCommandEvent $event): void
    {
        $pattern = '/--' . SnapctlApplication::BACKGROUND_OPTION_NAME . '\b\s*/';
        $inputStr = trim(preg_replace($pattern, '', $this->getInputString($event)));

        /*
         * Command:
         *   snapctl asset:backup:start 10.0.30.4
         * Sample screen name:
         *   snapctl-assetbackupstart-c75e56a1
         */
        $screenName = sprintf(
            'snapctl-%s-%s',
            str_replace(':', '', $event->getCommand()->getName()),
            substr(md5(time() . mt_rand(10000000, 99999999)), 1, 8)
        );
        $this->processFactory
            ->get(['screen', '-dmS', $screenName, 'bash', '-c', SnapctlApplication::EXECUTABLE_NAME . ' ' . $inputStr])
            ->mustRun();

        $event->getOutput()->writeln('<info>Running in background screen: ' . $screenName . '</info>');
        exit;
    }

    /**
     * Globally enforces inhibitAllCron for snapctl commands.
     *
     * @param ConsoleCommandEvent $event
     */
    public function handleCronOption(ConsoleCommandEvent $event): void
    {
        if ($this->deviceConfig->has('inhibitAllCron')) {
            $cmdLine = SnapctlApplication::EXECUTABLE_NAME . ' ' . $this->getInputString($event);
            $event->getOutput()->writeln("<error>Cannot run '${cmdLine}' when inhibitAllCron is enabled.</error>");
            $event->disableCommand();
        }
    }

    /**
     * Globally enforces fuzz option.
     *
     * Fuzzing is done by deviceId, with 60-random-seconds to distribute across each minute
     * This is used to make a particular device sleep a consistent number of minutes between tasks
     *
     * @param ConsoleCommandEvent $event
     */
    public function handleFuzzOption(ConsoleCommandEvent $event): void
    {
        $minutes = (int)$event->getInput()->getOption(SnapctlApplication::FUZZ_OPTION_NAME);
        if ($minutes > 1) {
            $this->fuzz($event, $minutes);
        }
    }

    private function fuzz(ConsoleCommandEvent $event, int $minutes): void
    {
        $deviceId = (int) $this->deviceConfig->getDeviceId();
        $sleepSeconds = (($deviceId % $minutes) * 60) + rand(1, 60);
        $event->getOutput()->writeln('<info>Sleeping ' . $sleepSeconds . ' seconds for task fuzzing</info>');
        $this->sleep->sleep($sleepSeconds);
    }

    /**
     * Check whether multiple instances of this command are allowed to run concurrently
     */
    private function multipleInstancesAllowed(Command $command): bool
    {
        if ($command instanceof AbstractCommand) {
            return $command->multipleInstancesAllowed();
        }

        return true;
    }

    private function fuzzAllowed(?Command $command): bool
    {
        if ($command instanceof AbstractCommand) {
            return $command->fuzzAllowed();
        }

        return true;
    }

    /**
     * Enforce that only a single instance of the command process is running on the system.
     * If another command with the same name is already running, we kill this command.
     */
    private function handleSingleInstanceOnly(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()->getName();
        $lock = $this->lockFactory->getProcessScopedLock(LockInfo::COMMAND_LOCK_DIR . $name);

        if (!$lock->exclusive(false)) {
            $event->getOutput()->writeln('<info>Command is already running. Exiting. LockFile:' . $lock->path() . '</info>');
            exit;
        }
    }

    /**
     * Return the InputInterface as a string.
     * This was copied from vendor/symfony/console/EventListener/ErrorListener.php
     *
     * @param ConsoleCommandEvent $event
     * @return string InputInterface as a string
     */
    private function getInputString(ConsoleCommandEvent $event): string
    {
        $input = $event->getInput();
        method_exists($input, '__toString') ? $inputAsString = (string)$input : $inputAsString = '';
        return $inputAsString;
    }
}
