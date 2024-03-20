<?php

namespace Datto\App\Console\Command\Network;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Core\Network\WindowsDomain;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes a device from a Windows Active Directory Domain
 */
class DomainLeaveCommand extends AbstractCommand
{
    protected static $defaultName = 'network:domain:leave';

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
        $this->setDescription('Leaves a Windows Active Directory domain');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->windowsDomain->leave();
        return 0;
    }
}
