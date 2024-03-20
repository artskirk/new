<?php

namespace Datto\App\Console\Command\Network;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Core\Network\WindowsDomain;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Updates the Windows Active Directory DNS Server with the current device address(es)
 */
class DomainUpdateDnsCommand extends AbstractCommand
{
    protected static $defaultName = 'network:domain:updateDns';

    private WindowsDomain $windowsDomain;

    public function __construct(WindowsDomain $windowsDomain)
    {
        parent::__construct();
        $this->windowsDomain = $windowsDomain;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_SERVICE_SAMBA];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Updates the domain DNS server with current device IP addresses');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // This command will do nothing if the device is not currently configured for domain membership
        $this->windowsDomain->updateDns();
        return 0;
    }
}
