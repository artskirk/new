<?php

namespace Datto\App\Console\Command\Apache;

use Datto\Apache\ApacheService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Configures Apache sites according to the device type.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class ConfigureCommand extends Command
{
    protected static $defaultName = 'apache:configure';

    /** @var ApacheService */
    private $apache;

    public function __construct(
        ApacheService $apache
    ) {
        parent::__construct();

        $this->apache = $apache;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->apache->configure();
        return 0;
    }
}
