<?php

declare(strict_types=1);

namespace Datto\App\Console\Command\Device\CommonLookAndFeel;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Device\ClfService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DisableClfCommand extends AbstractCommand
{
    protected static $defaultName = 'device:clf:disable';
    private ClfService $clfService;

    public function __construct(
        ClfService $clfService
    ) {
        parent::__construct();
        $this->clfService = $clfService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_USER_INTERFACE];
    }

    protected function configure(): void
    {
        $this->setDescription('Disable CLF (Common Look and Feel).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('CLF0002 Disabling Common Look and Feel User Interface.');
        $this->clfService->toggleClf(false);
        return 0;
    }
}
