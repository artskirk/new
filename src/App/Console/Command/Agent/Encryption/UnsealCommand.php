<?php

namespace Datto\App\Console\Command\Agent\Encryption;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\KeyStashService;
use Datto\Asset\Agent\EncryptionService;
use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Utility\Security\SecretString;
use Exception;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Unseals the given agent using its passphrase.
 * This is required for backups of the agent to run.
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class UnsealCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:encryption:unseal';

    /** @var EncryptionService */
    private $encryptionService;

    /** @var KeyStashService */
    private $keyStashService;

    public function __construct(
        EncryptionService $encryptionService,
        KeyStashService $keyStashService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->encryptionService = $encryptionService;
        $this->keyStashService = $keyStashService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Load an agent\'s master key into memory')
            ->addArgument('agent', InputArgument::REQUIRED, 'The agent to load the master key of');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agent = $input->getArgument('agent');

        if ($this->encryptionService->isAgentMasterKeyLoaded($agent)) {
            $output->writeln('Agent is already unsealed.');
            return 0;
        }

        $passphraseQuestion = new Question('Enter passphrase:');
        $passphraseQuestion->setHidden(true);
        $passphraseQuestion->setHiddenFallback(false);
        $passphraseQuestion->setValidator(function ($value) {
            if (empty($value)) {
                throw new Exception('The passphrase cannot be empty');
            }
            return $value;
        });

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $passphrase = new SecretString($helper->ask($input, $output, $passphraseQuestion));

        $this->encryptionService->decryptAgentKey($agent, $passphrase);
        $output->writeln('Agent unsealed.');
        return 0;
    }
}
