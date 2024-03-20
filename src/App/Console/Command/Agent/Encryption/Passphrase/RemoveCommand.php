<?php

namespace Datto\App\Console\Command\Agent\Encryption\Passphrase;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\CloudEncryptionService;
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
 * Removes a passphrase from an agent.
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class RemoveCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:encryption:passphrase:remove';

    /** @var EncryptionService */
    private $encryptionService;

    /** @var CloudEncryptionService */
    private $cloudEncryptionService;

    public function __construct(
        EncryptionService $encryptionService,
        CloudEncryptionService $cloudEncryptionService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->encryptionService = $encryptionService;
        $this->cloudEncryptionService = $cloudEncryptionService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Removes a passphrase from an agent')
            ->addArgument('agent', InputArgument::REQUIRED, 'The agent to remove the passphrase from');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agent = $input->getArgument('agent');
        if ($this->agentService->get($agent)->getOriginDevice()->isReplicated()) {
            throw new Exception('Replicated agents cannot have their passphrases removed.');
        }

        $passphraseQuestion = new Question('Enter passphrase to remove:');
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

        $this->encryptionService->removeAgentPassphrase($agent, $passphrase);
        $this->cloudEncryptionService->uploadEncryptionKeys();
        $output->writeln('Passphrase removed.');
        return 0;
    }
}
