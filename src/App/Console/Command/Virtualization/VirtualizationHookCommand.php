<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Feature\FeatureService;
use Datto\Restore\Virtualization\VirtualizationHookHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VirtualizationHookCommand extends AbstractCommand
{
    protected static $defaultName = 'virtualization:hook';

    private VirtualizationHookHandler $virtHookHandler;

    public function __construct(
        VirtualizationHookHandler $virtHookHandler
    ) {
        $this->virtHookHandler = $virtHookHandler;
        parent::__construct();
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_VIRTUALIZATION];
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Process a Virtualization Hook')
            ->addArgument('guestName', InputArgument::REQUIRED, 'Affected libvirt Guest Name')
            ->addArgument('vmState', InputArgument::REQUIRED, 'VM State (stopped, start, ...)')
            ->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vmState = $input->getArgument('vmState');
        $guestName = $input->getArgument('guestName');

        $this->virtHookHandler->onHookReceive($guestName, $vmState);
        return 0;
    }
}
