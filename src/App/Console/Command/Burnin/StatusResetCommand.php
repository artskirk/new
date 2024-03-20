<?php

namespace Datto\App\Console\Command\Burnin;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Onboarding\BurninService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to reset burnin status to "never_run". Used for testing and if burnin fails and needs to be manually
 * restarted.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class StatusResetCommand extends AbstractCommand
{
    protected static $defaultName = 'burnin:status:reset';

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
            ->setDescription('Command to reset burnin status to "never_run"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->burninService->resetStatus();
        return 0;
    }
}
