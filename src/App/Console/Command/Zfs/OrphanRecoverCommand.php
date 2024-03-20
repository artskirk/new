<?php
namespace Datto\App\Console\Command\Zfs;

use Datto\Asset\OrphanDatasetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

/**
 * Recovers an orphan ZFS dataset.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class OrphanRecoverCommand extends Command
{
    protected static $defaultName = 'zfs:orphan:recover';

    /** @var OrphanDatasetService */
    private $recoveryService;

    public function __construct(
        OrphanDatasetService $recoveryService
    ) {
        parent::__construct();

        $this->recoveryService = $recoveryService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Recover a single orphan ZFS dataset and place it in the archived state')
            ->addArgument(
                "name",
                InputArgument::REQUIRED,
                "Name of dataset to recover"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument("name");
        $datasets = $this->recoveryService->findOrphanDatasets();
        foreach ($datasets as $dataset) {
            $fullDatasetName = $dataset->getName();
            $assetKeyName = $this->recoveryService->getAssetKeyName($dataset);
            if ($name === $fullDatasetName || $name === $assetKeyName) {
                try {
                    $this->recoveryService->recoverDataset($dataset);
                    $output->writeln("Successfully recovered orphan dataset.");
                    return 0;
                } catch (Exception $e) {
                    $output->writeln("Unable to recover: " . $e->getMessage());
                    return 2;
                }
            }
        }
        $output->writeln("Unable to recover: There is no orphan dataset matching name \"$name\".");
        return 1;
    }
}
