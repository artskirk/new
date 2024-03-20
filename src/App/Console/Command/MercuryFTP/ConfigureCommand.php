<?php

namespace Datto\App\Console\Command\MercuryFTP;

use Datto\Mercury\MercuryFtpService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigureCommand extends Command
{
    protected static $defaultName = 'mercuryftp:configure';

    private MercuryFtpService $mercuryFtpService;

    public function __construct(MercuryFtpService $mercuryFtpService)
    {
        parent::__construct();

        $this->mercuryFtpService = $mercuryFtpService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Configure MercuryFTP');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->mercuryFtpService->configure();
        return 0;
    }
}
