<?php

namespace Datto\App\Console\Command\Internal\Agent;

use Datto\Service\AssetManagement\Create\CreateAgentService;
use Datto\Utility\Security\SecretString;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Used internally to create agents in the background. This is triggered by the wizard.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AgentCreateCommand extends Command
{
    protected static $defaultName = 'internal:agent:create';

    /** @var CreateAgentService */
    private $createAgentService;

    public function __construct(
        CreateAgentService $createAgentService
    ) {
        parent::__construct();

        $this->createAgentService = $createAgentService;
    }

    /**
     * @return bool
     */
    public function isHidden()
    {
        return true;
    }

    protected function configure()
    {
        $this
            ->setDescription('Creates an agent. Used internally.')
            ->addOption('domainName', null, InputOption::VALUE_REQUIRED, '', '')
            ->addOption('moRef', null, InputOption::VALUE_REQUIRED, '', '')
            ->addOption('connectionName', null, InputOption::VALUE_REQUIRED, '', '')
            ->addOption('offsiteTarget', null, InputOption::VALUE_REQUIRED)
            ->addOption('uuid', null, InputOption::VALUE_REQUIRED)
            ->addOption('agentKeyToCopy', null, InputOption::VALUE_REQUIRED, '', '')
            ->addOption('useLegacyKeyName', null, InputOption::VALUE_NONE)
            ->addOption('fullDisk', null, InputOption::VALUE_NONE, '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domainName = $input->getOption('domainName');
        $moRef = $input->getOption('moRef');
        $connectionName = $input->getOption('connectionName');
        $offsiteTarget = $input->getOption('offsiteTarget');
        $uuid = $input->getOption('uuid');
        $agentKeyToCopy = $input->getOption('agentKeyToCopy');
        $useLegacyKeyName = $input->getOption('useLegacyKeyName');
        $fullDisk = $input->getOption('fullDisk');

        $this->createAgentService->doPair(
            $uuid,
            $domainName,
            $moRef,
            $connectionName,
            $offsiteTarget,
            new SecretString(''), // Encryption was already set up before since we can't pass this safely on the cli
            $agentKeyToCopy,
            $useLegacyKeyName,
            false,
            $fullDisk
        );
        return 0;
    }
}
