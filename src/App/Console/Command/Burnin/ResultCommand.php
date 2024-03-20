<?php

namespace Datto\App\Console\Command\Burnin;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Onboarding\BurninService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to get burnin result.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ResultCommand extends AbstractCommand
{
    protected static $defaultName = 'burnin:result';

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

    protected function configure()
    {
        $this
            ->setDescription('Command to get burnin result');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode($this->burninService->getFinishedResult()));
        return 0;
    }
}
