<?php

namespace Datto\App\Console\Command\Security\IntrusionDetection;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Security\IntrusionDetection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to configure intrusion detection. This will be run on boot if the feature is enabled via systemd.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ConfigureCommand extends AbstractCommand
{
    protected static $defaultName = 'security:intrusiondetection:configure';

    /** @var IntrusionDetection */
    private $intrusionDetection;

    public function __construct(IntrusionDetection $intrusionDetection)
    {
        parent::__construct();

        $this->intrusionDetection = $intrusionDetection;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_INTRUSION_DETECTION
        ];
    }

    protected function configure()
    {
        $this
            ->addOption(
                'key',
                null,
                InputOption::VALUE_REQUIRED,
                'Use a key instead of fetching one from device-web'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getOption('key');

        $this->intrusionDetection->configure($key);

        return 0;
    }
}
