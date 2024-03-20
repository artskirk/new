<?php

namespace Datto\App\Console\Command\Azure\Imds;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Utility\Azure\InstanceMetadata;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GetInterfacesCommand extends AbstractCommand
{
    protected static $defaultName = 'azure:imds:interfaces';

    /** @var InstanceMetadata */
    private $instanceMetadata;

    public function __construct(InstanceMetadata $instanceMetadata)
    {
        parent::__construct();
        $this->instanceMetadata = $instanceMetadata;
    }

    public static function getRequiredFeatures(): array
    {
        return [];
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode($this->instanceMetadata->getInterfaces()));
        return 0;
    }
}
