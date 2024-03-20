<?php

namespace Datto\App\Console\Command\Device\Environment;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Service\Device\EnvironmentService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to create the device environment variable file
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class SetEnvironmentCommand extends AbstractCommand
{
    protected static $defaultName = 'device:environment:set';

    private EnvironmentService $envService;

    public function __construct(EnvironmentService $envService)
    {
        parent::__construct();
        $this->envService = $envService;
    }

    public static function getRequiredFeatures(): array
    {
        return [];
    }

    protected function configure()
    {
        $this->setDescription('Create the device environment variable file.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->envService->writeEnvironment();
        return 0;
    }
}
