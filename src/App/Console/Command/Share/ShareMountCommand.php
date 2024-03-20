<?php

namespace Datto\App\Console\Command\Share;

use Datto\App\Console\Command\AbstractShareCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Share\ShareService;
use Datto\Feature\FeatureService;
use Datto\Util\RetryHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Responsible for mounting shares to /datto/mounts/<shareName>
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class ShareMountCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:mount';

    private RetryHandler $retryHandler;

    private FeatureService $featureService;

    public function __construct(
        CommandValidator $commandValidator,
        ShareService $shareService,
        RetryHandler $retryHandler,
        FeatureService $featureService
    ) {
        parent::__construct($commandValidator, $shareService);
        $this->retryHandler = $retryHandler;
        $this->featureService = $featureService;
    }

    public function multipleInstancesAllowed(): bool
    {
        return false;
    }

    protected function configure(): void
    {
        $this->setDescription('Mount shares.')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to mount.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Mount all shares.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateShare($input);
        $shares = $this->getShares($input);

        foreach ($shares as $share) {
            $canMount = $this->featureService->isSupported(FeatureService::FEATURE_SHARE_AUTO_MOUNTING, null, $share);
            if (!$canMount) {
                continue;
            }

            try {
                $this->logger->setAssetContext($share->getKeyName());
                $this->retryHandler->executeAllowRetry(function () use ($share) {
                    $share->mount();
                    $this->logger->debug("SHR0005 Mounted share {$share->getKeyName()}");
                }, 5, 1);
            } catch (\Throwable $e) {
                $this->logger->critical('SHR0004 Failed to mount share', ['error' => $e->getMessage()]);
            }
        }
        return 0;
    }
}
