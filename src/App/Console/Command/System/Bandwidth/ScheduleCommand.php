<?php

namespace Datto\App\Console\Command\System\Bandwidth;

use Datto\Service\Offsite\BandwidthLimitService;
use Datto\Service\Registration\RegistrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Apply the scheduled bandwidth restrictions based on the current system time.
 *
 * @author Mark Blakley <mblakley@datto.com>
 * @codeCoverageIgnore
 */
class ScheduleCommand extends Command
{
    protected static $defaultName = 'system:bandwidth:schedule';

    private BandwidthLimitService $bandwidthLimitService;

    private RegistrationService $registrationService;

    public function __construct(
        BandwidthLimitService $bandwidthLimitService,
        RegistrationService $registrationService
    ) {
        parent::__construct();

        $this->bandwidthLimitService = $bandwidthLimitService;
        $this->registrationService = $registrationService;
    }

    protected function configure()
    {
        $this->setDescription('Apply scheduled bandwidth restrictions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->registrationService->isRegistered()) {
            try {
                $this->bandwidthLimitService->applyBandwidthRestrictions();

                $output->writeln('Offsite bandwidth restrictions applied.');
            } catch (Throwable $t) {
                $output->writeln('Unexpected error occurred while applying offsite bandwidth restrictions.');
            }
        } else {
            $output->writeln('Skipping application of bandwidth restrictions until the device is registered');
        }
        return 0;
    }
}
