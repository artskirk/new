<?php

namespace Datto\App\Console\Command\Upgrade;

use Datto\Log\LoggerAwareTrait;
use Datto\Common\Resource\Sleep;
use Datto\Upgrade\ChannelService;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to get the device's current upgrade channel
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class ChannelGetCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'upgrade:channel:get';

    const SECONDS_IN_FOUR_HOURS = 14400;

    /** @var ChannelService */
    private $service;

    /** @var Sleep */
    private $sleep;

    public function __construct(
        ChannelService $service,
        Sleep $sleep
    ) {
        parent::__construct();

        $this->service = $service;
        $this->sleep = $sleep;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Get and cache the device\'s current upgrade channel, and a list of available channels')
            ->addOption(
                'nosleep',
                null,
                InputOption::VALUE_NONE,
                'If supplied, get the channel immediately; otherwise the script will sleep for a random period up to 1 day'
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shouldSleep = !$input->getOption('nosleep');
        if ($shouldSleep) {
            $this->sleep();
        }

        $this->service->updateCache();
        $channels = $this->service->getChannels();

        $selectedChannel = $channels->getSelected();
        $output->write(sprintf("The device is currently using the %s upgrade channel\n", $selectedChannel));

        $availableChannels = $channels->getAvailable();
        $output->write(sprintf("Available channels: %s\n", implode($availableChannels, ', ')));
        return 0;
    }

    private function sleep(): void
    {
        $timeout = mt_rand(1, self::SECONDS_IN_FOUR_HOURS);

        $this->logger->debug('CGC0001 Get device upgrade channel is sleeping for ' . $timeout . ' seconds');
        $this->sleep->sleep($timeout);
    }
}
