<?php

namespace Datto\App\Console;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\HttpKernel\KernelInterface;

class SnapctlApplication extends Application
{
    const EXECUTABLE_NAME = 'snapctl';
    const BACKGROUND_OPTION_NAME = 'background';
    const CRON_OPTION_NAME = 'cron';
    const FUZZ_OPTION_NAME = 'fuzz';

    /** @var FeatureService */
    private $featureService;

    /**
     * @param KernelInterface $kernel
     * @param FeatureService|null $featureService
     */
    public function __construct(KernelInterface $kernel)
    {
        parent::__construct($kernel);

        // Get FeatureService manually from the container because this class is the entry point for the symfony app and
        // therefore dependencies cannot be injected automatically through this constructor
        $this->featureService = $kernel->getContainer()->get(FeatureService::class);
    }

    public function getHelp(): string
    {
        return '';
    }

    /**
     * Override add function to add --cron and --background options
     * to all commands.
     *
     * @param Command $command
     * @return null|Command
     */
    public function add(Command $command)
    {
        $command->getDefinition()->addOption(
            new InputOption(
                self::BACKGROUND_OPTION_NAME,
                null,
                InputOption::VALUE_NONE,
                'Run in a screen in the background'
            )
        );
        $command->getDefinition()->addOption(
            new InputOption(
                self::CRON_OPTION_NAME,
                null,
                InputOption::VALUE_NONE,
                'Run as a cron task'
            )
        );
        $command->getDefinition()->addOption(
            new InputOption(
                self::FUZZ_OPTION_NAME,
                null,
                InputOption::VALUE_REQUIRED,
                'fuzz, minutes across which to fuzz'
            )
        );

        return parent::add($command);
    }

    /**
     * @param string|null $namespace
     * @return Command[]
     */
    public function all($namespace = null)
    {
        $commands = parent::all($namespace);
        $features = array_flip($this->featureService->getCachedSupported(true));

        foreach ($commands as $command) {
            // Exclude symfony commands from showing up in snapctl
            if (strpos(get_class($command), 'Datto') === false) {
                $command->setHidden(true);
            }

            if (!($command instanceof AbstractCommand)) {
                continue;
            }

            foreach ($command::getRequiredFeatures() as $requiredFeature) {
                $isSupported = array_key_exists($requiredFeature, $features);

                if (!$isSupported) {
                    $command->setHidden(true);
                    break;
                }
            }
        }

        return $commands;
    }
}
