<?php

namespace Datto\App\Console\Command\Ipmi;

use Datto\Ipmi\IpmiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for restoring from a backed up IPMI firmware.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class IpmiRestoreCommand extends Command
{
    protected static $defaultName = 'ipmi:restore';

    /** @var IpmiService */
    private $ipmiService;

    public function __construct(
        IpmiService $ipmiService
    ) {
        parent::__construct();

        $this->ipmiService = $ipmiService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Optional path to firmware backup location.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = $input->getOption('from');

        $this->ipmiService->restore($from);
        return 0;
    }
}
