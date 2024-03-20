<?php

namespace Datto\App\Console\Command\Device\Country;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Config\ServerNameConfig;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CountryCommand extends AbstractCommand
{
    const ARG_COUNTRY = 'country';

    protected static $defaultName = 'device:country';

    /** @var ServerNameConfig */
    private $serverNameConfig;

    public function __construct(ServerNameConfig $serverNameConfig)
    {
        parent::__construct();
        $this->serverNameConfig = $serverNameConfig;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_DEVICE_INFO];
    }

    protected function configure()
    {
        $this
            ->setDescription('Command to get or set the country of the device.')
            ->addArgument(
                self::ARG_COUNTRY,
                InputArgument::OPTIONAL,
                'Set the country to this value. If omitted, get the country stored.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getArgument(self::ARG_COUNTRY)) {
            $newCountry = $input->getArgument(self::ARG_COUNTRY);
            $this->serverNameConfig->setCountry($newCountry);
        } else {
            $output->writeln($this->serverNameConfig->getCountry());
        }
        return 0;
    }
}
