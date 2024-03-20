<?php

namespace Datto\App\Console\Command\System\SpeedSync;

use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Resource\DateTimeService;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pause SpeedSync for a given number of hours.
 *
 * @author Peter Geer <pgeer@datto.com>
 * @codeCoverageIgnore
 */
class PauseCommand extends Command
{
    protected static $defaultName = 'system:speedsync:pause';

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncMaintenanceService;

    public function __construct(
        SpeedSyncMaintenanceService $speedSyncMaintenanceService
    ) {
        parent::__construct();

        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Pause SpeedSync.')
            ->addOption('hours', 'H', InputOption::VALUE_REQUIRED, 'Number of hours to enable the flag for (default is 0, or indefinite pause if no other options add time).')
            ->addOption('minutes', 'M', InputOption::VALUE_REQUIRED, 'Number of minutes to enable the flag for (default is 0, or indefinite pause if no other options add time).')
            ->addOption('seconds', 'S', InputOption::VALUE_REQUIRED, 'Number of seconds to enable the flag for (default is 0, or indefinite pause if no other options add time).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maxHours = SpeedSyncMaintenanceService::MAX_DELAY_IN_SECONDS / DateTimeService::SECONDS_PER_HOUR;
        $delayHours = $this->getValidOption($input, 'hours', $maxHours);
        $delayMinutes = $this->getValidOption($input, 'minutes', SpeedSyncMaintenanceService::MAX_DELAY_IN_SECONDS / DateTimeService::SECONDS_PER_MINUTE);
        $delaySeconds = $this->getValidOption($input, 'seconds', SpeedSyncMaintenanceService::MAX_DELAY_IN_SECONDS);

        $totalSeconds = ($delayHours * DateTimeService::SECONDS_PER_HOUR) + ($delayMinutes * DateTimeService::SECONDS_PER_MINUTE) + $delaySeconds;

        if ($totalSeconds <= 0) {
            $totalSeconds = SpeedSyncMaintenanceService::MAX_DELAY_IN_SECONDS;
        } elseif ($totalSeconds > SpeedSyncMaintenanceService::MAX_DELAY_IN_SECONDS) {
            throw new \Exception(
                'Invalid total time from sum of --hours, --minutes, and --seconds. Total time in seconds must be an integer between 1 and ' .
                SpeedSyncMaintenanceService::MAX_DELAY_IN_SECONDS . '.'
            );
        }

        $this->speedSyncMaintenanceService->pause($totalSeconds);

        $output->writeln(sprintf('SpeedSync paused until %s', date("g:ia M jS Y", $this->speedSyncMaintenanceService->getResumeTime())));
        return 0;
    }

    private function getValidOption(InputInterface $input, string $optionName, int $maxValue): int
    {
        $optionValue = $input->getOption($optionName);

        if (!$optionValue) {
            $optionValue = 0;
        }

        $numericValue = (int)$optionValue;

        if ($numericValue < 0 || $numericValue > $maxValue) {
            throw new Exception(
                "Invalid argument for --$optionName. Must be an integer between 0 and " .
                $maxValue . '.'
            );
        }

        return $numericValue;
    }
}
