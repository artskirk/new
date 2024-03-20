<?php

namespace Datto\App\Console\Command\Zfs\Cache;

use Datto\Log\LoggerAwareTrait;
use Datto\ZFS\ZfsService;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Update the device owned zfs cache
 *
 * @author Justin Giacobbi <justin@datto.com>
 */
class UpdateCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'zfs:cache:update';

    /** @var ZfsService */
    protected $zfsService;

    public function __construct(
        ZfsService $zfsService
    ) {
        parent::__construct();

        $this->zfsService = $zfsService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Update device owned zfs cache');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->debug("ZCU0001 Updating zfs cache, this may take a few moments");

        try {
            $this->zfsService->writeCache();

            $this->logger->debug("ZCU0002 Cache update complete");
            return 0;
        } catch (\Throwable $e) {
            $this->logger->warning("ZCU0003 Failed to update zfs cache", ['error' => $e->getMessage()]);
            return 1;
        }
    }
}
