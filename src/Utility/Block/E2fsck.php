<?php

namespace Datto\Utility\Block;

use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\AbstractUtility;

/**
 * Utility to interact with the `e2fsck` command.
 *
 * @author Nathan Blair <nblair@datto.com>
 */
class E2fsck extends AbstractUtility
{
    const FSCK = 'e2fsck';
    const FSCK_FORCE_FLAG = '-f';
    const FSCK_NO_INTERACTIVE_FLAG = '-a';
    const FSCK_EXIT_GOOD = 0;
    const FSCK_EXIT_ERRORS_FIXED = 1;
    const TIMEOUT = 432000; // 5 days

    private ProcessFactory $processFactory;

    /**
     * @param ProcessFactory $processFactory
     */
    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Run e2fsck against the specified path (probably a loop device) to see if it has errors.
     *
     * @param string $path the path to fsck
     * @return bool whether the filesystem has errors
     */
    public function hasErrors(string $path): bool
    {
        $args = [
            E2fsck::FSCK,
            E2fsck::FSCK_FORCE_FLAG,
            E2fsck::FSCK_NO_INTERACTIVE_FLAG,
            $path
        ];

        $this->logger->info('EFS0001 Checking filesystem for errors.', [ 'args' => $args ]);

        $process = $this->processFactory->get(
            $args,
            null,
            null,
            null,
            E2fsck::TIMEOUT
        );

        $process->run();

        // fsck can exit with 1 and still be considered a successful run
        //
        // sample output:
        // root@nblairubu:/mnt# e2fsck -f -a /dev/loop42p1
        // dev/loop42p1: 13/65536 files (0.0% non-contiguous), 8859/261888 blocks
        // root@nblairubu:/mnt# echo $?
        // 0
        $allowedExitCodes = [self::FSCK_EXIT_GOOD, self::FSCK_EXIT_ERRORS_FIXED];
        if (!in_array($process->getExitCode(), $allowedExitCodes)) {
            $this->logger->error(
                'EFS0002 Filesystem contains errors. Unable to run e2fsck.',
                [ 'errorOutput' => $process->getErrorOutput() ]
            );
            return true;
        }

        return false;
    }
}
