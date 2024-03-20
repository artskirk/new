<?php

namespace Datto\App\Console\Command\Ipmi;

use Datto\Common\Resource\ProcessFactory;
use Datto\Feature\FeatureService;
use Datto\Ipmi\IpmiService;
use Datto\System\ModuleManager;
use Datto\Utility\Systemd\Systemctl;
use Datto\Common\Resource\Sleep;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command to setup IPMI required services/drivers if a BMC is detected on the host.
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class IpmiSetupCommand extends Command
{
    protected static $defaultName = 'ipmi:setup';

    /** @const RETURN_OK IPMI appears to be operational */
    const RETURN_OK = 0;

    /** @const RETURN_BMC_NOT_DETECTED The probe for BMC hardware failed to find anything. */
    const RETURN_BMC_NOT_DETECTED = 1;

    /** @const RETURN_BMC_DETECTED_BUT_NOT_SUPPORTED While a BMC was detected, openipmi may not have a driver for it. */
    const RETURN_BMC_DETECTED_BUT_NOT_SUPPORTED = 2;

    /** @const SERVICE_OPENIPMI Name of the openipmi service */
    const SERVICE_OPENIPMI = 'openipmi';

    private ProcessFactory $processFactory;

    /** @var ModuleManager */
    private $moduleManager;

    /** @var Systemctl */
    private $systemctl;

    /** @var FeatureService */
    private $featureService;

    /** @var IpmiService */
    private $ipmiService;

    /** @var Sleep */
    private $sleep;

    public function __construct(
        ProcessFactory $processFactory,
        ModuleManager $moduleManager,
        Systemctl $systemctl,
        FeatureService $featureService,
        IpmiService $ipmiService,
        Sleep $sleep
    ) {
        parent::__construct();

        $this->processFactory = $processFactory;
        $this->moduleManager = $moduleManager;
        $this->systemctl = $systemctl;
        $this->featureService = $featureService;
        $this->ipmiService = $ipmiService;
        $this->sleep = $sleep;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Probes for IPMI hardware and adjusts services/drivers if required.')
            ->addOption('sleep-until-settled', null, InputOption::VALUE_NONE, 'Sleep until for an arbitrary amount of time to allow the system to boot/settle.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('sleep-until-settled')) {
            // Wait for the system to settle (startup processes, upgrades, etc to finish)
            $output->writeln("Sleeping for 1 hour to allow for the system to settle ...");
            $this->sleep->sleep(3600);
        }

        $this->attemptUpdateIfNeeded($output);

        // The IPMI feature class tries to run an ipmitool command, so we use it for checking if it is operational.
        if ($this->featureService->isSupported(FeatureService::FEATURE_IPMI)) {
            $output->writeln('ok');
            return self::RETURN_OK;
        }

        if (!$this->hasIPMI()) {
            $output->writeln('A Baseboard Management Controller (BMC) was not detected on this host.');
            $this->disableIpmiServicesAndModules($output);
            return self::RETURN_BMC_NOT_DETECTED;
        }

        if (!$this->featureService->isSupported(FeatureService::FEATURE_IPMI)) {
            // A BMC was detected, but the ipmitool command is not working as expected
            $this->enableOpenIpmi($output);
        }

        if (!$this->featureService->isSupported(FeatureService::FEATURE_IPMI)) {
            // openipmi may not have loaded the complete set of appropriate drivers, so attempt to complete the process.
            $this->moduleManager->addModule('ipmi_devintf', null, true);
            $this->sleep->sleep(2); // device node creation may take a moment
        }

        if (!$this->featureService->isSupported(FeatureService::FEATURE_IPMI)) {
            $output->writeln('<error>BMC hardware was detected, but could not be accessed. openipmi may be unable to find an appropriate driver.</error>');
            $this->disableIpmiServicesAndModules($output);
            return self::RETURN_BMC_DETECTED_BUT_NOT_SUPPORTED;
        }

        return self::RETURN_OK;
    }

    /**
     * @param OutputInterface $output
     */
    private function attemptUpdateIfNeeded(OutputInterface $output): void
    {
        try {
            $this->ipmiService->updateIfNeeded();
        } catch (\Throwable $e) {
            $output->writeln($e->getTraceAsString());
        }
    }

    /**
     * Test for IPMI hardware. Since dmidecode provides unreliable results, a more active probe is performed.
     * Since ipmi-locate generates a burst of noisy logs in syslog, this command should not be run frequently.
     *
     * @return bool
     */
    protected function hasIPMI()
    {
        // fyi: sensors-detect is another possibility to avoid including the freeipmi-tools package,
        //      but would require creating and maintaining an expect script to wrap it

        // Unsuccessful probes contain the word FAILED, but successful probes contain the word done
        $process = $this->processFactory
            ->getFromShellCommandLine('ipmi-locate | egrep "^Probing" | grep done');
        $process->run();
        return $process->getExitCode() == 0;
    }

    /**
     * Enables and starts the openipmi service. If running it is restarted.
     *
     * @param OutputInterface $output
     */
    private function enableOpenIpmi(OutputInterface $output): void
    {
        $output->writeln('Ensuring openipmi service is running...');
        $this->systemctl->stop(self::SERVICE_OPENIPMI);
        $this->systemctl->start(self::SERVICE_OPENIPMI);
        $this->systemctl->enable(self::SERVICE_OPENIPMI);
        sleep(2); // it may take a moment or two for driver loading and device node creation
    }

    /**
     * Disables the openipmi service and removes related drivers if necessary.
     *
     * @param OutputInterface $output
     */
    private function disableIpmiServicesAndModules(OutputInterface $output): void
    {
        $output->writeln('Disabing openipmi service...');

        $this->systemctl->disable(self::SERVICE_OPENIPMI);
        $this->systemctl->stop(self::SERVICE_OPENIPMI);
        $this->moduleManager->removeModule('ipmi_devintf', true, true);

        // Clear the failed state of the service (CP-12914)
        if ($this->systemctl->isFailed(self::SERVICE_OPENIPMI)) {
            $this->systemctl->resetFailed(self::SERVICE_OPENIPMI);
        }
    }
}
