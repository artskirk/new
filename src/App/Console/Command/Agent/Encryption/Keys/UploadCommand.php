<?php

namespace Datto\App\Console\Command\Agent\Encryption\Keys;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Encryption\CloudEncryptionService;
use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Uploads encryption key stashes to the cloud.
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class UploadCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:encryption:keys:upload';

    /** @var CloudEncryptionService */
    private $cloudEncryptionService;

    public function __construct(
        CloudEncryptionService $cloudEncryptionService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->cloudEncryptionService = $cloudEncryptionService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Upload encryption key stashes to the cloud')
            ->addArgument('agent', InputArgument::OPTIONAL, 'Registers encryption record for the agent. Use only when the agent is first paired.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cloudEncryptionService->uploadEncryptionKeys($input->getArgument('agent'));
        $output->writeln('Key stashes uploaded.');
        return 0;
    }
}
