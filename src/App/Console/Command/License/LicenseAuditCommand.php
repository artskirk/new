<?php

namespace Datto\App\Console\Command\License;

use Datto\License\LicenseService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LicenseAuditCommand extends Command
{
    protected static $defaultName = 'license:audit';

    /** @var LicenseService */
    private $licenseService;

    public function __construct(
        LicenseService $licenseService
    ) {
        parent::__construct();

        $this->licenseService = $licenseService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Audits the number of agents running.')
            ->addOption('nosleep', null, InputOption::VALUE_NONE, 'Run the audit immediately without sleeping');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $noSleep = $input->getOption('nosleep');

        try {
            $output->writeln("Running License Audit");
            $this->licenseService->auditLicensesAndReport($noSleep);
            return 0;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
            return 1;
        }
    }
}
