<?php

namespace Datto\Utility\Block;

use Datto\Common\Resource\ProcessFactory;

class XfsInfo
{
    public const BLOCKS_KEY = 'blocks';

    public const BLOCK_SIZE_KEY = 'bsize';

    private const REGEX_XFS_SIZE = '/data[ ]+=[ ]+bsize=(?<%s>[0-9]+)?[ ]+blocks=(?<%s>[0-9]+)?/';

    private const INFO_COMMAND = 'xfs_info';

    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    public function getBlocksInfo(string $path): array
    {
        $process = $this->processFactory->get([
            self::INFO_COMMAND,
            $path
        ]);
        $process->mustRun();

        preg_match(
            sprintf(self::REGEX_XFS_SIZE, self::BLOCK_SIZE_KEY, self::BLOCKS_KEY),
            $process->getOutput(),
            $matches
        );

        return $matches;
    }
}
