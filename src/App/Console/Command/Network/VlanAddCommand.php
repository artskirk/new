<?php

namespace Datto\App\Console\Command\Network;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Feature\FeatureService;
use Datto\Service\Networking\LinkBackup;
use Datto\Service\Networking\LinkService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VlanAddCommand extends AbstractCommand
{
    protected static $defaultName = 'network:vlan:add';
    private LinkService $linkService;

    public function __construct(LinkService $linkService, string $name = null)
    {
        parent::__construct($name);
        $this->linkService = $linkService;
    }

    protected function configure()
    {
        $this->setDescription('Adds a vlan to connection.  ' . LinkBackup::COMMIT_REQUIRED_NOTIFICATION_TEXT)
            ->addArgument("guid", InputArgument::REQUIRED, "Guid for the network connection")
            ->addArgument("vlanid", InputArgument::REQUIRED, "The vlan id");
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_NETWORK];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $guid = $input->getArgument("guid");
        $vlanid = $input->getArgument("vlanid");
        $this->linkService->addVlan($guid, $vlanid);
        print(LinkBackup::COMMIT_REQUIRED_NOTIFICATION_TEXT . PHP_EOL);
        return 0;
    }
}
