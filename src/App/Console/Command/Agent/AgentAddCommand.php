<?php

namespace Datto\App\Console\Command\Agent;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\Agent\PairFactory;
use Datto\Asset\UuidGenerator;
use Datto\Cloud\SpeedSync;
use Datto\Feature\FeatureService;
use Datto\Security\PasswordService;
use Datto\Service\AssetManagement\Create\CreateAgentService;
use Datto\Utility\Security\SecretString;
use Exception;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class AgentAddCommand extends AbstractCommand
{
    protected static $defaultName = 'agent:add';

    /** @var PairFactory */
    private $pairFactory;

    /** @var PasswordService */
    private $passwordService;

    /** @var CreateAgentService */
    private $createAgentService;

    /** @var FeatureService */
    private $featureService;

    /** @var UuidGenerator */
    private $uuidGenerator;

    public function __construct(
        PairFactory $pairFactory,
        PasswordService $passwordService,
        CreateAgentService $createAgentService,
        FeatureService $featureService,
        UuidGenerator $uuidGenerator
    ) {
        parent::__construct();

        $this->pairFactory = $pairFactory;
        $this->passwordService = $passwordService;
        $this->createAgentService = $createAgentService;
        $this->featureService = $featureService;
        $this->uuidGenerator = $uuidGenerator;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_AGENTS,
            FeatureService::FEATURE_AGENT_BACKUPS,
            FeatureService::FEATURE_AGENT_CREATE
        ];
    }

    protected function configure()
    {
        $this
            ->setDescription('Adds an agent.')
            ->setHelp('Registers a new agent or repairs an existing agent. Optionally if the system is on an ESX host, add the hypervisor connection name.')
            ->addArgument('hostname', InputArgument::REQUIRED, 'The hostname of the agent to add.')
            ->addOption('hypervisor', 'H', InputOption::VALUE_REQUIRED, 'The hypervisor connection name.')
            ->addOption('encrypted', null, InputOption::VALUE_NONE, 'The agent is to be encrypted')
            ->addOption('offsiteTarget', 'o', InputOption::VALUE_REQUIRED, 'This specifies the target for offsiting. Can be "cloud", "noOffsite", or a device ID for peer to peer.', SpeedSync::TARGET_CLOUD)
            ->addOption('useLegacyKeyName', null, InputOption::VALUE_NONE, 'Creates the agent with the legacy domain name style keyName. FOR TESTING ONLY.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'If set and pairing a ShadowSnap agent, this will enable SMB minimum version 1 automatically.')
            ->addOption('fullDisk', null, InputOption::VALUE_NONE, 'Force an agentless pairing to back up full disk images instead of filesystems');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hostname = $input->getArgument('hostname');
        $hypervisor = $input->getOption('hypervisor');
        $isEncrypted = $input->getOption('encrypted');
        $offsiteTarget = $input->getOption('offsiteTarget');
        $useLegacyKeyName = $input->getOption('useLegacyKeyName');
        $force = $input->getOption('force');
        $fullDisk = $input->getOption('fullDisk');

        // If the encryption flag is set, ask for the encryption passphrase and use that if validated
        $passphrase = $isEncrypted ? new SecretString($this->validateEncryptionPassphrase($input, $output)) : null;

        if ($this->featureService->isSupported(FeatureService::FEATURE_NEW_PAIR)) {
            $createdAssetKey = $this->createAgentService->doPair(
                $this->uuidGenerator->get(),
                $hypervisor ? '' : $hostname,
                $hypervisor ? $hostname : '',
                $hypervisor ?? '',
                $offsiteTarget,
                $passphrase ?? new SecretString(''),
                '',
                $useLegacyKeyName,
                $force,
                $fullDisk
            );
            $output->writeln($createdAssetKey);
        } else {
            $pairHandler = $this->pairFactory->create($hostname, $hypervisor, $passphrase, $offsiteTarget, $force, $fullDisk);
            $output->writeln($pairHandler->run());
        }
        return 0;
    }

    /**
     * Validates the encryption passphrase by asking for it twice and comparing the two.
     * If the two are the same, return it as a string, otherwise throw an exception.
     * If the passphrase is blank, still accept it, but the agent will not be encrypted.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return string
     */
    private function validateEncryptionPassphrase(InputInterface $input, OutputInterface $output): string
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $passphraseQuestion = new Question('Enter encryption passphrase:');
        $passphraseQuestion->setHidden(true);
        $passphraseQuestion->setHiddenFallback(false);

        $confirmationQuestion = new Question('Confirm encryption passphrase:');
        $confirmationQuestion->setHidden(true);
        $confirmationQuestion->setHiddenFallback(false);

        $passphrase = $helper->ask($input, $output, $passphraseQuestion);
        $confirmationPassphrase = $helper->ask($input, $output, $confirmationQuestion);

        if (!$passphrase || !$confirmationPassphrase) {
            throw new Exception('Encryption passphrase is required');
        }

        if ($passphrase !== $confirmationPassphrase) {
            throw new Exception('Encryption passphrase and confirmation passphrase do not match');
        }

        $this->passwordService->validatePassword($passphrase, '');

        return $passphrase;
    }
}
