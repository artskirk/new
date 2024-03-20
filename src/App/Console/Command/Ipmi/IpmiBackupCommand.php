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
class IpmiBackupCommand extends Command
{
    protected static $defaultName = 'ipmi:backup';

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
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Optional path to firmware backup location.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = $input->getOption('to');

        $this->ipmiService->backup($to);
        return 0;
    }
}
