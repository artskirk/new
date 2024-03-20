<?php

namespace Datto\App\Console\Command\Ipmi;

use Datto\Ipmi\IpmiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for checking if an IPMI update is available.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class IpmiUpdateAvailableCommand extends Command
{
    protected static $defaultName = 'ipmi:update:available';

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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $available = $this->ipmiService->isUpdateAvailable();

        $output->writeln($available ? 'yes' : 'no');
        return $available ? 0 : 1;
    }
}
