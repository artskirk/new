<?php
namespace Datto\App\Console\Command\Zfs;

use Datto\Asset\OrphanDatasetService;
use Datto\ZFS\ZfsDataset;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

/**
 * Finds and lists orphan ZFS datasets.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class OrphanListCommand extends Command
{
    protected static $defaultName = 'zfs:orphan:list';

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
            ->setDescription(
                'List all ZFS datasets that represent an asset with no .agentInfo key file'
            )
            ->addOption('recoverable', null, InputOption::VALUE_NONE, 'Render only recoverable datasets')
            ->addOption('names', null, InputOption::VALUE_NONE, 'Render names of datasets that can be recovered');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $onlyRecoverable = $input->getOption('recoverable');

        $datasets = $this->getDatasets($onlyRecoverable);

        if ($input->getOption('names')) {
            $this->renderNames($output, $datasets);
        } else {
            $this->renderTable($output, $datasets);
        }
        return 0;
    }

    /**
     * @param bool $onlyRecoverable
     * @return ZfsDataset[]
     */
    private function getDatasets(bool $onlyRecoverable): array
    {
        $datasets = $this->recoveryService->findOrphanDatasets();

        if ($onlyRecoverable) {
            $datasets = array_filter($datasets, function (ZfsDataset $dataset) {
                return $this->recoveryService->isDatasetRecoverable($dataset);
            });
        }

        return $datasets;
    }

    /**
     * @param OutputInterface $output
     * @param ZfsDataset[] $datasets
     */
    private function renderNames(OutputInterface $output, array $datasets): void
    {
        foreach ($datasets as $dataset) {
            $output->writeln($dataset->getName());
        }
    }

    /**
     * @param OutputInterface $output
     * @param array $datasets
     */
    private function renderTable(OutputInterface $output, array $datasets): void
    {
        $table = new Table($output);
        $table->setHeaders(['NAME', 'STATUS']);
        $rows = [];
        foreach ($datasets as $dataset) {
            $fullDatasetName = $dataset->getName();
            $status = 'Recoverable';
            try {
                $this->recoveryService->checkDatasetRecoverable($dataset);
            } catch (Exception $e) {
                $status = 'Not Recoverable: ' . $e->getMessage();
            }
            $rows[] = [$fullDatasetName, $status];
        }
        $table->setRows($rows);
        $table->render();
    }
}
