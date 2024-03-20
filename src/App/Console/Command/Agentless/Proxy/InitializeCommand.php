<?php

namespace Datto\App\Console\Command\Agentless\Proxy;

use Datto\Agentless\Proxy\AgentlessSessionId;
use Datto\Agentless\Proxy\AgentlessSessionService;
use Datto\Core\Security\Cipher;
use Datto\Common\Utility\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Initialize agentless proxy session
 *
 * @author Mario Rial <mrial@datto.com>
 */
class InitializeCommand extends Command
{
    protected static $defaultName = 'agentless:proxy:initialize';

    /** @var AgentlessSessionService */
    private $agentlessSessionService;

    /** @var Cipher */
    private $cipher;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        AgentlessSessionService $agentlessSessionService,
        Cipher $cipher,
        Filesystem $filesystem
    ) {
        parent::__construct();

        $this->agentlessSessionService = $agentlessSessionService;
        $this->cipher = $cipher;
        $this->filesystem = $filesystem;
    }

    protected function configure()
    {
        $this
            ->setDescription('Initializes agentless proxy to work with a specified VM')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The ESX Host.')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'The ESX User.')
            ->addOption('password-file', null, InputOption::VALUE_REQUIRED, 'A path to the file containing the ESX Password.')
            ->addOption('vm-name', null, InputOption::VALUE_REQUIRED, 'The VM Name')
            ->addOption(
                'agentless-session',
                null,
                InputOption::VALUE_REQUIRED,
                'Agentless session id to use'
            )
            ->addOption('force-nbd', null, InputOption::VALUE_NONE, 'Force VDDK to use NBD transport method')
            ->addOption('full-disk', null, InputOption::VALUE_NONE, 'Use full disk backup instead of partition based');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $input->getOption('host');
        $user = $input->getOption('user');
        $passwordFile = $input->getOption('password-file');
        $vmName = $input->getOption('vm-name');
        $agentlessSessionId = $input->getOption('agentless-session');
        $forceNbd = $input->getOption('force-nbd');
        $fullDiskBackup = $input->getOption('full-disk');

        $encryptedPassword = $this->filesystem->fileGetContents($passwordFile);
        $password = $this->cipher->decrypt($encryptedPassword);

        $table = new Table($output);
        $table
            ->setHeaders([
                [new TableCell('Initializing agentless proxy...', ['colspan' => 2])]
            ])
            ->setRows([
               ['Host:', $host],
               ['User:', $user],
               ['VM Name:', $vmName],
               ['Session Id:', $agentlessSessionId],
               ['Force NBD:', ($forceNbd ? 'true' : 'false')],
               ['Full Disk:', ($fullDiskBackup ? 'true' : 'false')]
            ])
            ->render();

        $sessionId = AgentlessSessionId::fromString($agentlessSessionId);
        $agentlessSession = $this->agentlessSessionService->createAgentlessSession(
            $host,
            $user,
            $password,
            $vmName,
            $sessionId,
            $forceNbd,
            $fullDiskBackup
        );

        $output->writeln("Agentless Session ID: " . $agentlessSession->getAgentlessSessionId());

        return 0;
    }
}
