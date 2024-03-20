<?php

namespace Datto\App\Console\Command\Network;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Networking\LinkBackup;
use Datto\Service\Networking\LinkService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BondRemoveCommand extends AbstractCommand
{

    private LinkService $linkService;
    protected static $defaultName = 'network:bond:remove';

    public function __construct(LinkService $linkService, string $name = null)
    {
        parent::__construct($name);
        $this->linkService = $linkService;
    }

    protected function configure()
    {
        $this->setDescription('Removes existing bond and adds standard bridges to ethernet interfaces.  ' .
            LinkBackup::COMMIT_REQUIRED_NOTIFICATION_TEXT);
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_NETWORK];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->linkService->removeBond();
        print(LinkBackup::COMMIT_REQUIRED_NOTIFICATION_TEXT . PHP_EOL);
        return 0;
    }
}
