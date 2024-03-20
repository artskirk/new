<?php

namespace Datto\App\Console\Command\Network;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Service\Networking\LinkBackup;
use Datto\Service\Networking\LinkService;
use Symfony\Component\Console\Input\InputArgument;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BondAddCommand extends AbstractCommand
{

    private LinkService $linkService;
    protected static $defaultName = 'network:bond:add';

    public function __construct(LinkService $linkService, string $name = null)
    {
        parent::__construct($name);
        $this->linkService = $linkService;
    }

    protected function configure()
    {
        $this->setDescription('Creates a bond from 2 or more bridge/ethernet interfaces.  ' . LinkBackup::COMMIT_REQUIRED_NOTIFICATION_TEXT)
            ->addArgument("bondMode", InputArgument::REQUIRED, "balance-rr, active-backup or 802.3ad")
            ->addArgument("memberLinkIds", InputArgument::IS_ARRAY, "UUIDs of interfaces to be bonded. Minimum 2 required. Interfaces should be bridge or ethernet devices.")
            ->addOption("primaryLinkId", "p", InputOption::VALUE_OPTIONAL, "Primary link id when bondMode is 'active-backup'.", "");
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_NETWORK];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bondMode = $input->getArgument('bondMode');
        $memberLinkIds = $input->getArgument('memberLinkIds');
        $primaryLinkId = "";
        if ($bondMode === 'active-backup') {
            $primaryLinkId = $input->getOption('primaryLinkId');
        }
        $this->linkService->createBond($bondMode, $memberLinkIds, $primaryLinkId);
        print(LinkBackup::COMMIT_REQUIRED_NOTIFICATION_TEXT . PHP_EOL);
        return 0;
    }
}
