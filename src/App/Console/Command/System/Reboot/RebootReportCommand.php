<?php

namespace Datto\App\Console\Command\System\Reboot;

use Datto\App\Console\Command\AbstractCommand;
use Datto\System\RebootReportHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class RebootReportCommand extends AbstractCommand
{
    /** @var RebootReportHelper */
    private $rebootReportHelper;

    protected static $defaultName = 'system:reboot:report';

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
        $this->setDescription('Report last reboot event');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->rebootReportHelper->report();

        return 0;
    }
}
