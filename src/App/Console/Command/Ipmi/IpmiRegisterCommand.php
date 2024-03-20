<?php

namespace Datto\App\Console\Command\Ipmi;

use Datto\Ipmi\IpmiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for registering IPMI with device-web.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class IpmiRegisterCommand extends Command
{
    protected static $defaultName = 'ipmi:register';

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
        $this->ipmiService->register();
        return 0;
    }
}
