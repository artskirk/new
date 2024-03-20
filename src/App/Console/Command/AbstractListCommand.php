<?php

namespace Datto\App\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractListCommand extends Command
{
    protected function configure()
    {
        $this
            ->setDefinition(new InputDefinition(array(
                new InputOption('raw', null, InputOption::VALUE_NONE, 'To output raw command list'),
                new InputOption('format', null, InputOption::VALUE_REQUIRED, 'The output format (txt, xml, json, or md)', 'txt'),
            )))
            ->setDescription('List sub-commands for ' . $this->getName());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // merge our definition with the applications
        $this->getApplication()
            ->setDefinition($this->getDefinition());

        $helper = new DescriptorHelper();
        $helper->describe($output, $this->getApplication(), array(
            'format' => $input->getOption('format'),
            'raw_text' => $input->getOption('raw'),
            'namespace' => $this->getName()
        ));
        return 0;
    }
}
