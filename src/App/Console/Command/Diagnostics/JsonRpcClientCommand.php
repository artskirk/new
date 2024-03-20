<?php

namespace Datto\App\Console\Command\Diagnostics;

use Datto\App\Console\Input\InputArgument;
use Datto\Cloud\JsonRpcClient;
use Datto\System\Api\DeviceApiClient;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Execute commands against a supported json-rpc server
 * @author Jason Lodice <jlodice@datto.com>
 */
class JsonRpcClientCommand extends Command
{
    const LOCAL_API_DEFAULT_USER = 'datto';
    const LOCAL_API_ADDRESS = '127.0.0.1';
    const TARGET_API_DEVICE_WEB = 'device-web';
    const TARGET_API_LOCAL = 'local';

    protected static $defaultName = 'json:rpc';

    private JsonRpcClient $deviceWebClient;

    private DeviceApiClient $deviceApiClient;

    public function __construct(
        JsonRpcClient $client,
        DeviceApiClient $deviceApiClient
    ) {
        parent::__construct();

        $this->deviceWebClient = $client;
        $this->deviceApiClient = $deviceApiClient;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Execute a json-rpc request to one of several supported servers.')
            ->addArgument('method', InputArgument::REQUIRED, 'The json-rpc method name')
            ->addOption('args', 'a', InputOption::VALUE_REQUIRED, 'Optional json encoded arguments')
            ->addOption('pretty', 'p', InputOption::VALUE_NONE, 'Optional pretty print of json response')
            ->addOption(
                'target-api',
                't',
                InputOption::VALUE_REQUIRED,
                "Optional target json-rpc server 'device-web' or 'local' (default)"
            )
            ->addOption(
                'username',
                'u',
                InputOption::VALUE_REQUIRED,
                'Optional web user name when targeting the local API'
            )
            ->addOption(
                'password',
                's',
                InputOption::VALUE_REQUIRED,
                'Optional web user password when targeting the local API'
            );
    }

    /**
     * @inheritDoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $method = $input->getArgument('method');

        $args = [];
        if (!is_null($input->getOption('args'))) {
            $args = json_decode($input->getOption('args'), true);
            if (!is_array($args) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException(
                    'Received malformed json encoded arguments: ' .
                    json_last_error_msg()
                );
            }
        }

        $targetApi = strtolower($input->getOption('target-api') ?? '');
        if ($targetApi === '' || $targetApi === self::TARGET_API_LOCAL) {
            $username = $input->getOption('username') ?? self::LOCAL_API_DEFAULT_USER;
            $password = $input->getOption('password') ?? $this->promptForPassword($input, $output);
            $this->deviceApiClient->connect(self::LOCAL_API_ADDRESS, $username, $password);

            $result = $this->deviceApiClient->call($method, $args);
        } elseif ($targetApi === self::TARGET_API_DEVICE_WEB) {
            $result = $this->deviceWebClient->queryWithId($method, $args);
        } else {
            throw new InvalidArgumentException("Unknown target API '$targetApi'");
        }

        $options = $input->getOption('pretty') === true ? JSON_PRETTY_PRINT : 0;
        $output->writeln(json_encode($result, $options));
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function isHidden(): bool
    {
        return true;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return string
     */
    private function promptForPassword(InputInterface $input, OutputInterface $output): string
    {
        $passphraseQuestion = new Question('Web User Password: ');
        $passphraseQuestion->setHidden(true);
        $passphraseQuestion->setHiddenFallback(false);

        $questionHelper = $this->getHelper('question');
        $password = $questionHelper->ask($input, $output, $passphraseQuestion);

        if (!is_string($password)) {
            $password = '';
        }

        return $password;
    }
}
