<?php

namespace Datto\App\Console\Command\Apache\Ca;

use Datto\Apache\ApacheService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCaCommand extends Command
{
    protected static $defaultName = 'apache:ca:update';

    private ApacheService $apacheService;

    public function __construct(
        ApacheService $apacheService
    ) {
        parent::__construct();

        $this->apacheService = $apacheService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Checks if cloudapi certificate used by apache has been updated and downloads if needed.')
            ->addOption('restart-service', 'r', InputOption::VALUE_NONE, 'If certificates change, restart the underlying service');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $restartService = $input->getOption('restart-service') ?? false;
        $this->apacheService->checkTrustedRootCertificates($restartService);
        return 0;
    }
}
