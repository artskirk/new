<?php

namespace Datto\App\Console\Command\Metrics;

use Datto\App\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Geoff Amey <gamey@datto.com>
 */
class MetricsMeasureCommand extends MetricsCommand
{
    protected static $defaultName = 'metrics:measure';

    protected function configure(): void
    {
        $this
            ->setDescription('Updates a gauge to an arbitrary amount')
            ->addArgument('metric', InputArgument::REQUIRED, 'The metric to set')
            ->addArgument('value', InputArgument::REQUIRED, 'The value of the metric');

        // Call the parent's configure function, which handles metric tag processing
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $value = $input->getArgument('value');
        if (!is_numeric($value)) {
            $output->writeln('Metric value must be an integer');
            return 1;
        }

        $this->collector->measure(
            $input->getArgument('metric'),
            intval($value),
            $this->getTags($input)
        );
        return 0;
    }
}
