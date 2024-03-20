<?php

namespace Datto\App\Console\Command\Agent\Encryption;

use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Util\ScriptInputHandler;
use Datto\Utility\Security\SecretString;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Implement snapctl command to allow granting temporary access to encrypted data without a passphrase
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class EnableCryptTempAccessCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:encryption:tempaccess:enable';

    /** @var  TempAccessService */
    private $tempAccessService;

    /** @var ScriptInputHandler */
    private $scriptInputHandler;

    public function __construct(
        TempAccessService $tempAccessService,
        ScriptInputHandler $scriptInputHandler,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->tempAccessService = $tempAccessService;
        $this->scriptInputHandler = $scriptInputHandler;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Grant temporary passwordless encrypted data access to an agent')
            ->addArgument('agent', InputArgument::REQUIRED, 'Agent to grant passwordless access to');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentName = $input->getArgument('agent');
        $agent = $this->agentService->get($agentName);

        if ($agent->getEncryption()->isEnabled()) {
            $output->write("Passphrase:");
            $passphrase = new SecretString($this->scriptInputHandler->readHiddenInput());
            $output->writeln("");
            $this->tempAccessService->enableCryptTempAccess($agentName, $passphrase);
            $output->writeln("Temporary access enabled for $agentName");
        } else {
            $output->writeln('Only compatible with encrypted agents');
        }
        return 0;
    }
}
