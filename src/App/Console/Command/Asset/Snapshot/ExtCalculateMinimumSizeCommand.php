<?php

namespace Datto\App\Console\Command\Asset\Snapshot;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Log\DeviceLoggerInterface;
use Datto\Utility\Block\Blockdev;
use Datto\Utility\Block\E2fsck;
use Datto\Utility\Block\Resize2fs;
use Datto\Utility\Block\Stat;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

/**
 * This command wraps the standard `resize2fs`, `e2fsck`, `blockdev`, and `stat` commands so that we can run them in
 * sequence but treat them as a single call from the Siris API in an asynchronous start/progress/stop manner.
 *
 * The data written to stdout is used by upstream processes, so should not be changed without careful consideration.
 */
class ExtCalculateMinimumSizeCommand extends AbstractCalculateMinimumSizeCommand
{
    protected static $defaultName = 'asset:snapshot:ext:calcminsize';

    private Blockdev $blockDev;
    private E2fsck $e2fsck;
    private Resize2fs $resize2fs;
    private Stat $stat;

    public function __construct(
        CommandValidator $commandValidator,
        AssetService $assetService,
        ProcessFactory $processFactory = null,
        Blockdev $blockDev = null,
        E2fsck $e2fsck = null,
        Resize2fs $resize2fs = null,
        Stat $stat = null,
        DeviceLoggerInterface $logger = null
    ) {
        parent::__construct($commandValidator, $assetService, $processFactory);

        $this->blockDev = $blockDev ?? new Blockdev($processFactory);
        $this->e2fsck = $e2fsck ?? new E2fsck($processFactory);
        $this->resize2fs = $resize2fs ?? new Resize2fs($processFactory);
        $this->stat = $stat ?? new Stat($processFactory);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Calculate the minimum size for an EXT partition')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the EXT partition');
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getOption('path');

        $result = $this->calculateMinimumSize($path, $output);

        $output->writeln('Successfully calculated minimum filesystem size');
        $output->writeln('Current volume size: ' . $result[ExtCalculateMinimumSizeCommand::ORIGINAL_SIZE] . ' bytes');
        $output->writeln('Minimum volume size: ' . $result[ExtCalculateMinimumSizeCommand::MIN_SIZE] . ' bytes');
        $output->writeln('Cluster size: ' . $result[ExtCalculateMinimumSizeCommand::CLUSTER_SIZE] . ' bytes');

        return 0;
    }

    /**
     * Calculates the minimum size by running resize2fs with estimate flag.
     *
     * @param string $path the path for the filesystem
     * @param OutputInterface $output the symfony output handle
     * @return array map containing the original, minimum, and block sizes
     * @throws Exception
     */
    private function calculateMinimumSize(string $path, OutputInterface $output): array
    {
        if ($this->e2fsck->hasErrors($path)) {
            $errorMessage = $path . ' cannot be resized';
            $output->writeln($errorMessage);
            throw new Exception($errorMessage, AbstractCalculateMinimumSizeCommand::CODE_CANNOT_RESIZE);
        } else {
            $minSize = $this->resize2fs->getMinimumSize($path);
            $originalSize = $this->blockDev->getSizeInBytes($path);
            $blockSize = $this->stat->getFilesystemBlockSize($path);
        }

        return [
            AbstractCalculateMinimumSizeCommand::ORIGINAL_SIZE => $originalSize,
            AbstractCalculateMinimumSizeCommand::MIN_SIZE => $minSize * $blockSize,
            AbstractCalculateMinimumSizeCommand::CLUSTER_SIZE => $blockSize
        ];
    }
}
