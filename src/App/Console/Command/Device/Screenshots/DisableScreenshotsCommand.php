<?php

namespace Datto\App\Console\Command\Device\Screenshots;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Verification\VerificationService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DisableScreenshotsCommand extends AbstractCommand
{
    protected static $defaultName = 'device:screenshots:disable';

    private VerificationService $verificationService;

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_VERIFICATIONS
        ];
    }

    public function __construct(VerificationService $verificationService)
    {
        parent::__construct();
        $this->verificationService = $verificationService;
    }

    protected function configure(): void
    {
        $this->setDescription('Disable screenshot verifications globally for the device');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->verificationService->setScreenshotsEnabled(false);

        return self::SUCCESS;
    }
}
