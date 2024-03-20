<?php

namespace Datto\App\Console\Command\Device\Country;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Config\ServerNameConfig;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CountryServersCommand extends AbstractCommand
{
    const OPTION_COUNTRY = 'country';
    const OPTION_SERVER = 'server';

    protected static $defaultName = 'device:country:servers';

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
            ->setDescription('Command to get or set servers for this device.')
            ->addOption(
                self::OPTION_COUNTRY,
                'c',
                InputOption::VALUE_REQUIRED,
                'Country to get/set servers for (uses /datto/config/country by default)'
            )
            ->addOption(
                self::OPTION_SERVER,
                's',
                InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED,
                'Servers to set for country (eg. "-s DEVICE_DATTOBACKUP_COM=ckosidevm.blah")'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption(self::OPTION_COUNTRY)) {
            $country = $input->getOption(self::OPTION_COUNTRY);
        } else {
            $country = $this->serverNameConfig->getCountry();
        }

        if ($input->getOption(self::OPTION_SERVER)) {
            $newServers = $this->parseSetServerOption($input->getOption(self::OPTION_SERVER));
            $this->serverNameConfig->setServers($country, $newServers);
        } else {
            $this->renderServers($output, $this->serverNameConfig->getServersByCountry($country));
        }
        return 0;
    }

    private function renderServers(OutputInterface $output, array $servers): void
    {
        $table = new Table($output);
        $table->setHeaders(['Name', 'Value']);
        foreach ($servers as $name => $value) {
            $table->addRow([$name, $value]);
        }
        $table->render();
    }

    /**
     * @param array $rawServers
     *      eg. ["DEVICE_DATTOBACKUP_COM=ckosidevm.blah"]
     * @return array
     */
    private function parseSetServerOption(array $rawServers): array
    {
        $servers = [];

        foreach ($rawServers as $rawServer) {
            list($name, $value) = explode('=', $rawServer, 2);

            $servers[$name] = $value;
        }

        return $servers;
    }
}
