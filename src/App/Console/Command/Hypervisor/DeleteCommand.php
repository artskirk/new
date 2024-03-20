<?php

namespace Datto\App\Console\Command\Hypervisor;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Connection\Service\ConnectionService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete a hypervisor connection.
 *
 * @author Krzysztof Smialek <krzysztof.smialek@datto.com>
 */
class DeleteCommand extends AbstractCommand
{
    protected static $defaultName = 'hypervisor:delete';

    private ConnectionService $connectionService;

    public function __construct(ConnectionService $connectionService)
    {
        parent::__construct();

        $this->connectionService = $connectionService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_HYPERVISOR_CONNECTIONS
        ];
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Delete a hypervisor connection')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of hypervisor connection')
        ;
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        $connection = $this->connectionService->get($name);
        if ($connection === null) {
            throw new \RuntimeException("Connection '$name' doesn't exist");
        }

        $this->connectionService->delete($connection);

        return self::SUCCESS;
    }
}
