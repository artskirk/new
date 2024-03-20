<?php

namespace Datto\App\Console\Command\Metrics\Offsite;

use Datto\Metrics\Offsite\OffsiteMetricsInitializer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OffsiteInitializeCommand extends Command
{
    protected static $defaultName = 'metrics:offsite:initialize';

    /** @var OffsiteMetricsInitializer */
    private $initializer;

    public function __construct(
        OffsiteMetricsInitializer $initializer
    ) {
        parent::__construct();

        $this->initializer = $initializer;
    }

    protected function configure()
    {
        $this
            ->setDescription('Initialize offsite metric points.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializer->initializeOffsiteMetricPointsFromSystem();

        return 0;
    }
}
