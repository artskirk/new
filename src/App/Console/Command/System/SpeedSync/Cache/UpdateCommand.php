<?php

namespace Datto\App\Console\Command\System\SpeedSync\Cache;

use Datto\Cloud\SpeedSync;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Resource\Sleep;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Update the device owned speedsync cache
 *
 * @author Justin Giacobbi <justin@datto.com>
 */
class UpdateCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'system:speedsync:cache:update';

    /** @var DeviceConfig */
    protected $deviceConfig;

    /** @var Sleep */
    protected $sleep;

    /** @var SpeedSync */
    protected $speedSync;

    public function __construct(
        DeviceConfig $deviceConfig,
        Sleep $sleep,
        SpeedSync $speedSync
    ) {
        parent::__construct();

        $this->deviceConfig = $deviceConfig;
        $this->sleep = $sleep;
        $this->speedSync = $speedSync;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Update device owned speedsync cache');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Updating cache, this may take a few moments...");

        try {
            $this->speedSync->writeCache();

            $output->writeln("Complete");
            return 0;
        } catch (\Throwable $e) {
            $this->logger->warning("SPE0001 Failed to update SpeedSync cache", ['error' => $e->getMessage()]);
            return 1;
        }
    }
}
