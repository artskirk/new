<?php

namespace Datto\App\Console\Command\Asset;

use Datto\Asset\RemountService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

/**
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class RemountAssetsCommand extends Command
{
    protected static $defaultName = 'asset:remount';

    /** @var RemountService */
    private $remountService;

    public function __construct(
        RemountService $remountService
    ) {
        parent::__construct();

        $this->remountService = $remountService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Remount all assets')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ignores whether this previously ran and remounts assets');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');

        if ($force) {
            $success = $this->remountService->remount();
        } else {
            $success = $this->remountService->remountIfNeeded();
        }

        return $success ? 0 : 1;
    }
}
