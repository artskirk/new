<?php

namespace Datto\App\Console\Command\System\Reboot;

use Datto\App\Console\Command\AbstractCommand;
use Datto\System\RebootReportHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class RebootCauseCommand extends AbstractCommand
{
    /** @var RebootReportHelper */
    private $rebootReportHelper;

    protected static $defaultName = 'system:reboot:cause';

    public function __construct(
        RebootReportHelper $rebootReportHelper
    ) {
        parent::__construct();

        $this->rebootReportHelper = $rebootReportHelper;
    }

    public static function getRequiredFeatures(): array
    {
        return [];
    }

    protected function configure()
    {
        $this
            ->setDescription('Set the reboot cause')
            ->addArgument('cause', InputArgument::REQUIRED, 'Cause of the reboot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cause = (string) $input->getArgument('cause');
        $hasChanged = $this->rebootReportHelper->causedBy($cause);

        return $hasChanged ? 0 : 1;
    }
}
