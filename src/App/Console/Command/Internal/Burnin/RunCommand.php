<?php

namespace Datto\App\Console\Command\Internal\Burnin;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Onboarding\BurninService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends AbstractCommand
{
    protected static $defaultName = 'internal:burnin:run';

    /** @var BurninService */
    private $burninService;

    public function __construct(BurninService $burninService)
    {
        parent::__construct();
        $this->burninService = $burninService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_BURNIN
        ];
    }

    public function isHidden(): bool
    {
        return true;
    }

    protected function configure()
    {
        $this
            ->setDescription('Worker command to handle running burnin in the background');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->burninService->run();
        return 0;
    }
}
