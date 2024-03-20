<?php

namespace Datto\App\Console\Command\Config\Cloud;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\CloudManagedConfig\CloudManagedConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends AbstractCommand
{
    protected static $defaultName = 'config:cloud:sync';

    private CloudManagedConfigService $cloudManagedConfigService;

    public function __construct(CloudManagedConfigService $cloudManagedConfigService)
    {
        parent::__construct();
        $this->cloudManagedConfigService = $cloudManagedConfigService;
    }

    public function multipleInstancesAllowed(): bool
    {
        return false;
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $pullResult = $this->cloudManagedConfigService->pullFromCloud();
        $this->cloudManagedConfigService->pushToCloud($pullResult);

        return Command::SUCCESS;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_CLOUD_MANAGED_CONFIGS
        ];
    }
}
