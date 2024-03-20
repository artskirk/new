<?php
namespace Datto\App\Console\Command\Upgrade;

use Datto\Upgrade\ChannelService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to set the device's upgrade channel
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class ChannelSetCommand extends Command
{
    protected static $defaultName = 'upgrade:channel:set';

    /** @var ChannelService */
    private $service;

    public function __construct(
        ChannelService $service
    ) {
        parent::__construct();

        $this->service = $service;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Set and cache the device\'s upgrade channel')
            ->addArgument('channel', InputArgument::REQUIRED, 'Upgrade channel to switch to');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelName = $input->getArgument('channel');
        $this->service->setChannel($channelName);
        $output->write(sprintf("The device's upgrade channel has been set to %s\n", $channelName));
        return 0;
    }
}
