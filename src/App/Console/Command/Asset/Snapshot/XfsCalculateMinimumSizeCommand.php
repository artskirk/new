<?php

namespace Datto\App\Console\Command\Asset\Snapshot;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\Block\XfsInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class XfsCalculateMinimumSizeCommand extends AbstractCalculateMinimumSizeCommand
{
    protected static $defaultName = 'asset:snapshot:xfs:calcminsize';

    private XfsInfo $xfsInfo;

    public function __construct(
        CommandValidator $commandValidator,
        AssetService     $assetService,
        XfsInfo          $xfsInfo,
        ProcessFactory   $processFactory = null
    ) {
        parent::__construct($commandValidator, $assetService, $processFactory);
        $this->xfsInfo = $xfsInfo;
    }


    protected function configure(): void
    {
        $this
            ->setDescription('Calculate the minimum size for an XFS partition')
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the XFS partition'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var string $path */
        $path = $input->getOption('path');

        try {
            $blocksInfo = $this->xfsInfo->getBlocksInfo($path);
            $size = ((int)$blocksInfo[XfsInfo::BLOCKS_KEY] * (int)$blocksInfo[XfsInfo::BLOCK_SIZE_KEY]);

            $output->writeln('Successfully calculated minimum filesystem size');
            $output->writeln(sprintf('Current volume size: %s bytes', $size));
            $output->writeln(sprintf('Minimum volume size: %s bytes', $size));
            $output->writeln(sprintf('Cluster size: %s bytes', $blocksInfo[XfsInfo::BLOCK_SIZE_KEY]));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $message = sprintf('%s cannot be resized.', $path);
            $output->writeln($message);

            throw new \Exception($message, AbstractCalculateMinimumSizeCommand::CODE_CANNOT_RESIZE);
        }
    }
}
