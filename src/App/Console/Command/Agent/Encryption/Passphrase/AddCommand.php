<?php

namespace Datto\App\Console\Command\Agent\Encryption\Passphrase;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\CloudEncryptionService;
use Datto\Asset\Agent\EncryptionService;
use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Security\PasswordService;
use Datto\Utility\Security\SecretString;
use Exception;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Adds a new passphrase to an agent.
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class AddCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:encryption:passphrase:add';

    /** @var EncryptionService */
    private $encryptionService;

    /** @var CloudEncryptionService */
    private $cloudEncryptionService;

    /** @var PasswordService */
    private $passwordService;

    public function __construct(
        EncryptionService $encryptionService,
        CloudEncryptionService $cloudEncryptionService,
        PasswordService $passwordService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->encryptionService = $encryptionService;
        $this->cloudEncryptionService = $cloudEncryptionService;
        $this->passwordService = $passwordService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Add a new passphrase to an agent')
            ->addArgument('agent', InputArgument::REQUIRED, 'The agent to add the passphrase to');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agent = $input->getArgument('agent');
        if ($this->agentService->get($agent)->getOriginDevice()->isReplicated()) {
            throw new Exception('Replicated agents cannot have their passphrases changed.');
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        // ask and verify existing passphrase (do not use temp-access here!)
        $oldQuestion = $this->getQuestion('Enter existing passphrase:');
        $oldPassphrase = new SecretString($helper->ask($input, $output, $oldQuestion));
        $this->encryptionService->decryptAgentKey($agent, $oldPassphrase);

        // ask, verify and set a new passphrase
        $newQuestion = $this->getQuestion('Enter new passphrase:');
        $newPassphrase = new SecretString($helper->ask($input, $output, $newQuestion));
        $this->passwordService->validatePassword($newPassphrase->getSecret(), '');
        $this->encryptionService->addAgentPassphrase($agent, $newPassphrase);
        $this->cloudEncryptionService->uploadEncryptionKeys();
        $output->writeln('Passphrase added.');
        return 0;
    }

    private function getQuestion(string $questionString): Question
    {
        $question = new Question($questionString);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator(function ($value) {
            if (empty($value)) {
                throw new Exception('The passphrase cannot be empty');
            }
            return $value;
        });

        return $question;
    }
}
