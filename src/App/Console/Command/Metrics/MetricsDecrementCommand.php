<?php

namespace Datto\App\Console\Command\Metrics;

use Datto\App\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Geoff Amey <gamey@datto.com>
 */
class MetricsDecrementCommand extends MetricsCommand
{
    protected static $defaultName = 'metrics:decrement';

    protected function configure(): void
    {
        $this
            ->setDescription('Decrement a metric')
            ->addArgument('metric', InputArgument::REQUIRED, 'The metric to decrement');

        // Call the parent's configure function, which handles metric tag processing
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->collector->decrement($input->getArgument('metric'), $this->getTags($input));
        return 0;
    }
}
