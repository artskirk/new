<?php

namespace Datto\App\Console\Command\Storage\PublicCloud;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Service\Storage\PublicCloud\PoolExpansionRunner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Expand the local storage pool if it's almost full.
 *
 * @author Dan Hentschel <dhentschel@datto.com>
 */
class PublicCloudExpandCheckCommand extends AbstractCommand
{
    private const PERCENT_FULL_OPTION_NAME = 'percentFull';
    private const FORCE_OPTION_NAME = 'force';

    // By default, we will expand when the pool is 80% full
    private const PERCENT_FULL_OPTION_DEFAULT = 80;

    protected static $defaultName = 'storage:public:expand:check';

    private CommandValidator $commandValidator;
    private DeviceConfig $deviceConfig;
    private PoolExpansionRunner $poolExpansionRunner;

    public function __construct(
        CommandValidator $commandValidator,
        DeviceConfig $deviceConfig,
        PoolExpansionRunner $poolExpansionRunner
    ) {
        $this->commandValidator = $commandValidator;
        $this->deviceConfig = $deviceConfig;
        $this->poolExpansionRunner = $poolExpansionRunner;

        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_PUBLIC_CLOUD_POOL_EXPANSION];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setDescription('Expand the local storage pool if it\'s almost full.')
             ->addOption(
                 self::PERCENT_FULL_OPTION_NAME,
                 null,
                 InputOption::VALUE_REQUIRED,
                 'The threshold (as a percentage of pool utilized) that will trigger an expansion',
                 $this->getDefaultThreshold()
             )
             ->addOption(
                 self::FORCE_OPTION_NAME,
                 null,
                 InputOption::VALUE_NONE,
                 'Ignore pool utilization and failure backoff and force an expansion immediately'
             );
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->commandValidator->validateValue(
            $input->getOption(self::PERCENT_FULL_OPTION_NAME),
            new Assert\Regex(['pattern' => '/^\d{1,2}$/']),
            self::PERCENT_FULL_OPTION_NAME . ' must be an integer between 0 and 99'
        );
    }

    /**
     * @inheritdoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);
        $threshold = (int) $input->getOption(self::PERCENT_FULL_OPTION_NAME);
        $force = $input->getOption(self::FORCE_OPTION_NAME);

        if ($force) {
            $this->poolExpansionRunner->forceStartExpansionOperation();
        } else {
            $this->poolExpansionRunner->conditionallyStartExpansionOperation($threshold);
        }
        return 0;
    }

    private function getDefaultThreshold()
    {
        $thresholdFromConfig = $this->deviceConfig->getStorageExpandThreshold();

        // Create the file if it doesn't exist
        if (empty($thresholdFromConfig)) {
            $this->deviceConfig->setStorageExpandThreshold(self::PERCENT_FULL_OPTION_DEFAULT);

            return self::PERCENT_FULL_OPTION_DEFAULT;
        }

        return $thresholdFromConfig;
    }
}
