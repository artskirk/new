<?php

namespace Datto\App\Console\Command\Billing;

use Datto\Asset\AssetService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Datto\Billing\Service;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Checks the status of the billing service for the device
 *
 * @author Mike Micatka <mmicatka@datto.com>
 *
 */
class CheckStatusCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'billing:checkstatus';

    /** @var Service */
    private $billingService;

    /** @var AssetService */
    private $assetService;

    public function __construct(
        Service $billingService,
        AssetService $assetService
    ) {
        parent::__construct();

        $this->billingService = $billingService;
        $this->assetService = $assetService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('check the service expiration')
            ->addOption('nosleep', 'N', InputOption::VALUE_NONE, 'Do not sleep');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('nosleep')) {
            $sleepTime = rand(1, 3600);
            $this->logger->debug('BSI0100 Service expiration check requested. Sleeping ' . $sleepTime . ' seconds; Try --nosleep to avoid that.');
            sleep($sleepTime);
        }

        $assets = $this->assetService->getAll();
        $this->billingService->getServiceInfo($assets);
        return 0;
    }
}
