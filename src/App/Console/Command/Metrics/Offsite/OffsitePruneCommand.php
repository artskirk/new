<?php

namespace Datto\App\Console\Command\Metrics\Offsite;

use Datto\Metrics\Offsite\OffsiteMetricsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OffsitePruneCommand extends Command
{
    protected static $defaultName = 'metrics:offsite:prune';

    /** @var OffsiteMetricsService */
    private $offsiteMetricsService;

    public function __construct(
        OffsiteMetricsService $offsiteMetricsService
    ) {
        parent::__construct();

        $this->offsiteMetricsService = $offsiteMetricsService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Remove old offsite metric points.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->offsiteMetricsService->prune();

        return 0;
    }
}
