<?php

namespace Datto\App\Console\Command\System;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\Certificate\CertificateHelper;
use Datto\Asset\Agent\Certificate\CertificateSetStore;
use Datto\Asset\Agent\Certificate\CertificateUpdateService;
use Datto\Asset\Agent\Windows\Api\ShadowSnapAgentApi;
use Datto\Config\AgentStateFactory;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Displays a report that summarizes the status of certificates on the device and the agents.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class CertificateListCommand extends AbstractCommand
{
    const UPDATE_AGENT_HASHES_OPTION = 'update';

    protected static $defaultName = 'system:certificate:list';

    /** @var CertificateUpdateService */
    private $certificateUpdateService;

    /** @var AgentService */
    private $agentService;

    /** @var AgentApiFactory */
    private $agentApiFactory;

    /** @var CertificateSetStore */
    private $certificateSetStore;

    /** @var CertificateHelper */
    private $certificateHelper;

    /** @var AgentStateFactory */
    private $agentStateFactory;

    public function __construct(
        CertificateUpdateService $certificateUpdateService,
        AgentService $agentService,
        AgentApiFactory $agentApiFactory,
        CertificateSetStore $certificateSetStore,
        CertificateHelper $certificateHelper,
        AgentStateFactory $agentStateFactory
    ) {
        parent::__construct();

        $this->certificateUpdateService = $certificateUpdateService;
        $this->agentService = $agentService;
        $this->agentApiFactory = $agentApiFactory;
        $this->certificateSetStore = $certificateSetStore;
        $this->certificateHelper = $certificateHelper;
        $this->agentStateFactory = $agentStateFactory;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_AGENT_BACKUPS
        ];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Display a summary of the certificate status for this device and all of its agents')
            ->addOption(self::UPDATE_AGENT_HASHES_OPTION, null, InputOption::VALUE_NONE, 'Find the working certs for all agents and update their stored hashes');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputStyle = new OutputFormatterStyle('yellow');
        $output->getFormatter()->setStyle('warning', $outputStyle);

        $deviceWebTrustedRootCertificate = $this->certificateHelper->retrieveTrustedRootContents();
        $deviceWebTrustedRootCertificateHash = md5($deviceWebTrustedRootCertificate);
        $certificateStoreSets = $this->certificateSetStore->getCertificateSets();
        $deviceStoredTrustedRootCertificateHash = $certificateStoreSets ? $certificateStoreSets[0]->getHash() : '';
        $deviceTrustedRootIsCurrent = $deviceStoredTrustedRootCertificateHash === $deviceWebTrustedRootCertificateHash;

        if ($input->getOption(self::UPDATE_AGENT_HASHES_OPTION)) {
            $this->certificateUpdateService->testAllLatestWorkingAgentCertificates();
        }

        // Add a blank line after log messages
        if ($output instanceof ConsoleOutputInterface) {
            $stdErrOutput = $output->getErrorOutput();
            $stdErrOutput->writeln('');
        }

        $output->writeln('Current Trusted Root Certificate Hash:');
        $message = $deviceWebTrustedRootCertificate ? $deviceWebTrustedRootCertificateHash : 'Could not fetch trusted root certificate from device-web';
        $output->writeln("    available on device-web:  $message");
        $output->write('    stored on this device:    ');
        if ($deviceTrustedRootIsCurrent) {
            $output->writeln($deviceStoredTrustedRootCertificateHash);
        } else {
            $output->writeln("<error>$deviceStoredTrustedRootCertificateHash</error>");
        }
        $output->writeln("");

        $table = new Table($output);
        $table->setHeaders(['Agent Key Name', 'Agent Type', 'Version', 'Trusted Root Certificate Hash']);

        $agents = $this->agentService->getAllActiveLocal();
        foreach ($agents as $agent) {
            if ($agent->communicatesWithoutCerts()) {
                continue;
            }

            $agentKeyName = $agent->getKeyName();
            $agentVersion = '';
            $agentType = '';

            try {
                $platform = $agent->getPlatform();
                $agentType = $platform->getFriendlyName();
                $agentVersion = $agent->getDriver()->getApiVersion();

                $api = $this->agentApiFactory->createFromAgent($agent);
                if ($api instanceof ShadowSnapAgentApi && !$api->isNewApiVersion()) {
                    // Shadowsnap agents older than 4.0.0 don't use these certificates to communicate
                    $agentHash = 'Does not use certificates';
                    continue;
                }

                $agentState = $this->agentStateFactory->create($agent->getKeyName());
                $agentStoredTrustedRootCertificateHash = $agentState->get(CertificateUpdateService::TRUSTED_ROOT_HASH_KEY, 'Not found');

                if ($agentStoredTrustedRootCertificateHash !== $deviceStoredTrustedRootCertificateHash) {
                    $agentHash = "<error>$agentStoredTrustedRootCertificateHash</error>";
                } elseif (!$deviceTrustedRootIsCurrent) {
                    $agentHash = "<warning>$agentStoredTrustedRootCertificateHash</warning>";
                } else {
                    $agentHash = $agentStoredTrustedRootCertificateHash;
                }
            } catch (Throwable $e) {
                $agentHash = 'Error';
                $this->logger->error('CLC0001 Error while listing certificates', ['error' => $e->getMessage()]);
            } finally {
                $table->addRow([$agentKeyName, $agentType, $agentVersion, $agentHash]);
            }
        }

        $table->render();
        return 0;
    }
}
