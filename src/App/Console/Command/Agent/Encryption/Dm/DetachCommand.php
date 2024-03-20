<?php

namespace Datto\App\Console\Command\Agent\Encryption\Dm;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DmCryptManager;
use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Common\Utility\Filesystem;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cleans up an attached dm-crypt device.
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class DetachCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:encryption:dm:detach';

    /** @var DmCryptManager */
    private $dmCryptManager;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        DmCryptManager $dmCryptManager,
        Filesystem $filesystem,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->dmCryptManager = $dmCryptManager;
        $this->filesystem = $filesystem;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Detach a dm-crypt device')
            ->addArgument('path', InputArgument::REQUIRED, 'The path to the encrypted image file, dm-crypt device, or intermediate loop device');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->filesystem->realpath($input->getArgument('path'));

        if ($path === false) {
            throw new Exception('The given path does not exist');
        }

        $this->dmCryptManager->detach($path);
        $output->writeln('Detached.');
        return 0;
    }
}
