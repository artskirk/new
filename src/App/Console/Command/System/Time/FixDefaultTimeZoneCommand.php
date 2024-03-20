<?php

namespace Datto\App\Console\Command\System\Time;

use Datto\System\Php\PhpConfigurationWriter;
use Datto\Util\DateTimeZoneService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command class for generating a PHP config that sets the default time-zone
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class FixDefaultTimeZoneCommand extends Command
{
    protected static $defaultName = 'system:time:fixDefaultTimeZone';

    private DateTimeZoneService $dateTimeZoneService;
    private PhpConfigurationWriter $phpConfigurationWriter;

    public function __construct(
        DateTimeZoneService $dateTimeZoneService,
        PhpConfigurationWriter $phpConfigurationWriter
    ) {
        parent::__construct();

        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->phpConfigurationWriter = $phpConfigurationWriter;
    }

    protected function configure()
    {
        $this->setDescription("Update PHP's default timezone");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Updating PHP's default timezone... ");

        try {
            $timeZone = $this->dateTimeZoneService->getTimeZone();
            $this->phpConfigurationWriter->setDefaultDateTimeZone($timeZone);

            $output->writeln('Done. (PHP-FPM/PHP-CGI must be restarted for this to take effect)');
            return 0;
        } catch (\Exception $e) {
            $output->writeln('An error occured:');
            $output->writeln($e->getMessage());
            return 1;
        }
    }
}
