<?php

namespace Datto\App\Console\Command\Restore\Iscsi;

use Datto\App\Console\Command\CommandValidator;
use Datto\Restore\Iscsi\IscsiMounterService;
use Datto\Util\DateTimeZoneService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Dakota Baber <dbaber@datto.com>
 */
class ListTargetsCommand extends AbstractIscsiCommand
{
    protected static $defaultName = 'restore:iscsi:list';

    /** @var DateTimeZoneService */
    private $dateTimeZoneService;

    public function __construct(
        DateTimeZoneService $dateTimeZoneService,
        CommandValidator $commandValidator,
        IscsiMounterService $iscsiMounterService
    ) {
        parent::__construct($commandValidator, $iscsiMounterService);

        $this->dateTimeZoneService = $dateTimeZoneService;
    }

    protected function configure()
    {
        $this
            ->setDescription('List iSCSI mounter targets info.');
    }

    /**
     * Prints a JSON representation of all the targets associated with iSCSI Mounter
     *
     * @param InputInterface $input The input interface
     * @param OutputInterface $output The output interface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $targets = $this->iscsiMounter->getAllTargetsInfo();

        if ($targets) {
            $table = new Table($output);
            $table->setHeaders(array(
                'Target',
                'Last Session',
                'Time since last session'
            ));

            $dateFormat = $this->dateTimeZoneService->universalDateFormat("date-time-long");
            foreach ($targets as $target) {
                $table->addRow(array(
                    $target['name'],
                    date($dateFormat, $target['lastSession']),
                    round($target['timeSinceLastSession'] / 60) . " minute(s) ago (" . $target['timeSinceLastSession'] . " seconds)"
                ));
            }

            $table->render();
        } else {
            $output->writeln("No targets.");
        }
        return 0;
    }
}
