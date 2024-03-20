<?php

namespace Datto\App\Console\Command\Asset\Snapshot;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\Block\NtfsResize;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command wraps the standard `ntfsresize` command so that we can use it from the Siris API, asynchronously. That
 * means:
 *   - Calculate an estimated (and quick) but possibly imprecise minimum filesystem size
 *   - Calculate a more precise (and possibly much slower) minimum filesystem size. Uses the input from the estimated
 *     command.
 *
 * The data written to stdout is used by upstream processes, so should not be changed without careful consideration.
 */
class NtfsCalculateMinimumSizeCommand extends AbstractCalculateMinimumSizeCommand
{
    protected static $defaultName = 'asset:snapshot:ntfs:calcminsize';

    const RESIZE_INFO_FAIL = 300;

    private NtfsResize $ntfsResize;

    public function __construct(
        CommandValidator $commandValidator,
        AssetService $assetService,
        ProcessFactory $processFactory = null,
        NtfsResize $ntfsResize = null
    ) {
        parent::__construct($commandValidator, $assetService, $processFactory);

        $this->ntfsResize = $ntfsResize ?? new NtfsResize($processFactory);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Calculate the minimum size for an NTFS partition')
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the NTFS partition'
            )
            ->addOption(
                'mode',
                null,
                InputOption::VALUE_REQUIRED,
                '"estimated" or "precise"',
                'estimated'
            )
            ->addOption(
                'recommended-min',
                null,
                InputOption::VALUE_REQUIRED,
                'The starting minimum size for the partition (precise mode)'
            )
            ->addOption(
                'cluster-size',
                null,
                InputOption::VALUE_REQUIRED,
                'The NTFS cluster size for the partition (precise mode)'
            )
            ->addOption(
                'original-size',
                null,
                InputOption::VALUE_REQUIRED,
                'The original size for the partition (precise mode)'
            );
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getOption('path');
        $mode = $input->getOption('mode');

        if ($mode == 'estimated') {
            $this->logger->debug('NCM0001 Obtaining estimated NTFS size');

            $result = $this->ntfsResize->calculateEstimatedMinimumSize($path);
        } else {
            $recommendedMin = $input->getOption('recommended-min');
            $clusterSize = $input->getOption('cluster-size');
            $originalSize = $input->getOption('original-size');

            $result = $this->ntfsResize->calculatePreciseMinimumSize($recommendedMin, $clusterSize, $originalSize, $path, $output);
        }

        $output->writeln('Current volume size: ' . $result[AbstractCalculateMinimumSizeCommand::ORIGINAL_SIZE] . ' bytes');
        $output->writeln('Minimum volume size: ' . $result[AbstractCalculateMinimumSizeCommand::MIN_SIZE] . ' bytes');
        $output->writeln('Cluster size: ' . $result[AbstractCalculateMinimumSizeCommand::CLUSTER_SIZE] . ' bytes');

        return 0;
    }
}
