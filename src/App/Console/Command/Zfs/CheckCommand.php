<?php

namespace Datto\App\Console\Command\Zfs;

use Datto\Service\Reporting\Zfs\ZfsCorruptionDataService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to check homePool for corruption and update the partner portal if any is found.
 * @author Christopher Bitler <cbitler@datto.com>
 */
class CheckCommand extends Command
{
    protected static $defaultName = 'zfs:check';

    /** @var ZfsCorruptionDataService */
    private $zfsCorruptionService;

    public function __construct(
        ZfsCorruptionDataService $zfsCorruptionService
    ) {
        parent::__construct();

        $this->zfsCorruptionService = $zfsCorruptionService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Checks the ZFS pools for errors and upload the data to the partner portal if it does not match the cached data');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->zfsCorruptionService->updateZfsCorruptionData();
        return 0;
    }
}
