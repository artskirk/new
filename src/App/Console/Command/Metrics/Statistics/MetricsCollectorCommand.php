<?php

namespace Datto\App\Console\Command\Metrics\Statistics;

use Datto\Config\DeviceConfig;
use Datto\Service\Metrics\Statistics;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class MetricsCollectorCommand extends Command
{
    protected static $defaultName = 'metrics:statistics:collect';

    private Statistics $statistics;
    private DeviceConfig $deviceConfig;

    public function __construct(
        Statistics $statistics,
        DeviceConfig $deviceConfig
    ) {
        parent::__construct();

        $this->statistics = $statistics;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Do not --fuzz on cloud devices.
     */
    public function fuzzAllowed(): bool
    {
        return !$this->deviceConfig->isCloudDevice();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Collect periodic device statistics');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->statistics->update();
        return 0;
    }
}
