<?php

namespace Datto\App\Console\Command\Migration;

use Datto\System\Api\DeviceApiClientService;
use Datto\Util\ScriptInputHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Set the source device for a device migration
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class SetSourceDeviceCommand extends Command
{
    protected static $defaultName = 'migrate:set:source';

    /** @var ScriptInputHandler */
    private $scriptInputHandler;

    /** @var DeviceApiClientService */
    private $deviceApiClientService;

    public function __construct(
        ScriptInputHandler $scriptInputHandler,
        DeviceApiClientService $deviceApiClientService
    ) {
        parent::__construct();

        $this->scriptInputHandler = $scriptInputHandler;
        $this->deviceApiClientService = $deviceApiClientService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Sets the source device for a device migration')
            ->addArgument(
                'hostname',
                InputArgument::REQUIRED,
                'Hostname of the source device'
            )
            ->addArgument(
                'username',
                InputArgument::REQUIRED,
                'The username to connect to the source device. This will prompt for a password.'
            );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hostname = trim($input->getArgument('hostname'));
        $username = trim($input->getArgument('username'));

        $password = null;
        if ($username) {
            $output->write('Password:');
            $password = $this->scriptInputHandler->readHiddenInput();
            $output->writeln(''); //since the user's return press is swallowed by readHiddenInput
        }

        $output->writeln("Checking connection");
        $this->deviceApiClientService->connect($hostname, $username, $password);
        $output->writeln("Connection successfully established");
        return 0;
    }
}
