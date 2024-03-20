<?php

namespace Datto\App\Console\Command\System;

use Datto\App\Console\Input\InputArgument;
use Datto\Log\LoggerAwareTrait;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Logs messages to the device log
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class LogCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'system:log';

    /**
     * @inheritdoc
     */
    public function isHidden(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription(
                'Log a message to the device log'
            )
            ->addArgument(
                'message',
                InputArgument::REQUIRED,
                'Message to be written to the log'
            )->addOption(
                'level',
                'l',
                InputOption::VALUE_REQUIRED,
                'Log level (error, warning, info, debug)',
                'info'
            )->addOption(
                'context',
                'c',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Context data in the form of key=value'
            );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $level = $input->getOption('level');
        $message = trim($input->getArgument('message'));

        $explodedContext = array_map(
            static function ($contextKeyValue) {
                if (strpos($contextKeyValue, '=') === false) {
                    throw new InvalidArgumentException('Context data must be provided in "key=value" format');
                }
                return explode('=', $contextKeyValue);
            },
            $input->getOption('context')
        );
        $context = array_combine(
            array_column($explodedContext, 0),
            array_column($explodedContext, 1)
        );

        $this->logger->log($level, $message, $context);
        return 0;
    }
}
