<?php

namespace Datto\App\Console\Command\Cloud\Ca;

use Datto\DirectToCloud\DirectToCloudService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCloudCaCommand extends Command
{
    protected static $defaultName = 'cloud:ca:update';

    private DirectToCloudService  $directToCloudService;

    public function __construct(
        DirectToCloudService $directToCloudService
    ) {
        parent::__construct();

        $this->directToCloudService = $directToCloudService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Checks if cloudbase certificate used by dtcserver has been updated and downloads if needed.')
            ->addOption('restart-service', 'r', InputOption::VALUE_NONE, 'If certificates change, restart the underlying service');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $restartService = $input->getOption('restart-service') ?? false;
        $this->directToCloudService->checkTrustedRootCertificates($restartService);
        return 0;
    }
}
