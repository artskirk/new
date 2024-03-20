<?php

namespace Datto\App\Console\Command\Device\StorageNode;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Registration\ActivationService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RegisterStorageNodeCommand extends AbstractCommand
{
    protected static $defaultName = 'device:storagenode:register';

    /** @var ActivationService */
    private $activationService;

    public function __construct(ActivationService $activationService)
    {
        parent::__construct();
        $this->activationService = $activationService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_AUTO_ACTIVATE];
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->activationService->autoRegisterStorageNode();
        return 0;
    }
}
