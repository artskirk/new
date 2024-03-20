<?php

namespace Datto\App\Console\Command\MercuryFTP;

use Datto\Mercury\MercuryFTPTLSService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks the TLS certificate(s) for the local Mercury FTP server
 */
class MercuryCheckTLS extends Command
{
    protected static $defaultName = 'mercuryftp:check:tls';

    private MercuryFTPTLSService $mercuryFtpTlsService;

    public function __construct(MercuryFTPTLSService $mercuryFtpTlsService)
    {
        parent::__construct();

        $this->mercuryFtpTlsService = $mercuryFtpTlsService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Checks the TLS certificate(s) for the local Mercury FTP server')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force TLS certificate check');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $this->mercuryFtpTlsService->checkTlsValidity($force);
        return 0;
    }
}
