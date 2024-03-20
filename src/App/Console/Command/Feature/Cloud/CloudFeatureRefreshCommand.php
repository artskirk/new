<?php

namespace Datto\App\Console\Command\Feature\Cloud;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Feature\CloudFeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CloudFeatureRefreshCommand extends AbstractCommand
{
    protected static $defaultName = 'feature:cloud:refresh';

    private CloudFeatureService $cloudFeatureService;

    public function __construct(CloudFeatureService $cloudFeatureService)
    {
        parent::__construct();
        $this->cloudFeatureService = $cloudFeatureService;
    }

    public function configure()
    {
        $this
            ->setDescription('Refresh cloud features stored locally');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cloudFeatureService->refresh();
        return 0;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_CLOUD_FEATURES
        ];
    }
}
