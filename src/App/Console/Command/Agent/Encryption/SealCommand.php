<?php

namespace Datto\App\Console\Command\Agent\Encryption;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\KeyStashService;
use Datto\Asset\Agent\EncryptionService;
use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seals the given agent (or optionally all agents).
 * This will prevent the given agent(s) from backing up!
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class SealCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:encryption:seal';

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
            ->setDescription('Discard an agent\'s master key from memory')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent to discard the master key of')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Discard the master key of all agents');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);

        foreach ($agents as $agent) {
            $assetKey = $agent->getKeyName();
            if (!$this->encryptionService->isEncrypted($assetKey)) {
                continue;
            }
            if (!$this->encryptionService->isAgentMasterKeyLoaded($assetKey)) {
                continue;
            }
            $this->keyStashService->unstash($assetKey);
        }
        return 0;
    }
}
