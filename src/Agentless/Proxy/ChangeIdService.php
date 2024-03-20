<?php

namespace Datto\Agentless\Proxy;

use Datto\Common\Resource\ProcessFactory;

/**
 * Service class to deal with reading and writing the changeIds from changeId/checksum files.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class ChangeIdService
{
    public const MERCURYFTP_COMMAND = 'mercuryftp';

    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * @param string[] $changeIdFiles
     * @return string[]
     */
    public function readChangeIds(array $changeIdFiles)
    {
        $changeIds = [];

        foreach ($changeIdFiles as $changeIdFile) {
            $changeIds[] = $this->readChangeId($changeIdFile);
        }

        return $changeIds;
    }

    /**
     * @param string $changeIdFile
     * @return string
     */
    public function readChangeId(string $changeIdFile): string
    {
        $process = $this->processFactory->get([self::MERCURYFTP_COMMAND, $changeIdFile, '/dev/stdout']);
        $process->mustRun();
        $changeId = trim(explode("\n", $process->getOutput())[0]);

        return $changeId;
    }

    /**
     * @param string $changeIdFile
     * @param string $changeId
     */
    public function writeChangeId(string $changeIdFile, string $changeId): void
    {
        $process = $this->processFactory
            ->get([self::MERCURYFTP_COMMAND, '/dev/stdin', $changeIdFile])
            ->setInput($changeId . "\n");

        $process->mustRun();
    }
}
