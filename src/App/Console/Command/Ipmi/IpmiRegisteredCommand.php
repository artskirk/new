<?php

namespace Datto\App\Console\Command\Ipmi;

use Datto\Ipmi\IpmiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class IpmiRegisteredCommand extends Command
{
    protected static $defaultName = 'ipmi:registered';

    /** @var IpmiService */
    private $ipmiService;

    /**
     * @param IpmiService $ipmiService
     */
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
        $output->writeln($this->ipmiService->isRegistered() ? 'yes' : 'no');
        return 0;
    }
}
