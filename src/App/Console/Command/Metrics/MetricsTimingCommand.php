<?php

namespace Datto\App\Console\Command\Metrics;

use Datto\App\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Geoff Amey <gamey@datto.com>
 */
class MetricsTimingCommand extends MetricsCommand
{
    protected static $defaultName = 'metrics:timing';

    protected function configure(): void
    {
        $this
            ->setDescription('Updates a timing metric')
            ->addArgument('metric', InputArgument::REQUIRED, 'The metric to set')
            ->addArgument('time', InputArgument::REQUIRED, 'The integer time to record');

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

        $this->collector->timing(
            $input->getArgument('metric'),
            intval($value),
            $this->getTags($input)
        );
        return 0;
    }
}
