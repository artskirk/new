<?php
namespace Datto\App\Console\Command\Share\Local;

use Datto\App\Console\Command\AbstractShareCommand;
use Datto\App\Console\Input\ScheduleDayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareLocalScheduleCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:local:schedule';

    private static $dayOptions = array(ScheduleDayInput::SUNDAY, ScheduleDayInput::MONDAY,
        ScheduleDayInput::TUESDAY, ScheduleDayInput::WEDNESDAY, ScheduleDayInput::THURSDAY,
        ScheduleDayInput::FRIDAY, ScheduleDayInput::SATURDAY
    );

    protected function configure()
    {
        $format = ScheduleDayInput::FORMAT_DESCRIPTION;
        $this
            ->setDescription("Change a share's schedule")
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'The share to change the schedule of')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Change the schedule for all current shares')
            ->addOption(ScheduleDayInput::SUNDAY, null, InputOption::VALUE_REQUIRED, "Set Sunday schedule ($format)")
            ->addOption(ScheduleDayInput::MONDAY, null, InputOption::VALUE_REQUIRED, "Set Monday schedule ($format)")
            ->addOption(ScheduleDayInput::TUESDAY, null, InputOption::VALUE_REQUIRED, "Set Tuesday schedule ($format)")
            ->addOption(ScheduleDayInput::WEDNESDAY, null, InputOption::VALUE_REQUIRED, "Set Wednesday schedule ($format)")
            ->addOption(ScheduleDayInput::THURSDAY, null, InputOption::VALUE_REQUIRED, "Set Thursday schedule ($format)")
            ->addOption(ScheduleDayInput::FRIDAY, null, InputOption::VALUE_REQUIRED, "Set Friday schedule ($format)")
            ->addOption(ScheduleDayInput::SATURDAY, null, InputOption::VALUE_REQUIRED, "Set Saturday schedule ($format)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $changedDays = $this->getChangedDays($input);
        $shares = $this->getShares($input);

        foreach ($shares as $share) {
            if ($share->getOriginDevice()->isReplicated()) {
                continue;
            }
            $localSchedule = $share->getLocal()->getSchedule();
            foreach ($changedDays as $day) {
                $localSchedule->setDay(
                    $day->getScheduleDay(),
                    $day->getHoursArray()
                );
            }
            $share->getLocal()->setSchedule($localSchedule);

            $share->getOffsite()->getSchedule()
                ->filter($localSchedule);

            $this->shareService->save($share);
        }
        return 0;
    }

    /**
     * Validate the input arguments
     *
     * @param InputInterface $input
     *
     * @return void
     */
    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);

        $scheduledDays = array();
        foreach (self::$dayOptions as $day) {
            $hoursString = $input->getOption($day);
            if (isset($hoursString)) {
                $scheduledDays[] = $hoursString;
                $scheduleDay = new ScheduleDayInput($day, $hoursString);
                $scheduleDay->validate($this->commandValidator);
            }
        }

        $this->commandValidator->validateValue(
            $scheduledDays,
            new Assert\Count(array('min' => 1)),
            'At least one day must have a schedule'
        );
    }

    /**
     * @param InputInterface $input
     * @return ScheduleDayInput[]
     */
    private function getChangedDays(InputInterface $input)
    {
        $changedDays = array();
        foreach (self::$dayOptions as $dayOption) {
            $hoursInput = $input->getOption($dayOption);
            if ($hoursInput) {
                $changedDays[] = new ScheduleDayInput($dayOption, $hoursInput);
            }
        }
        return $changedDays;
    }
}
