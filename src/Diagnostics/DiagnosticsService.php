<?php

namespace Datto\Diagnostics;

use Datto\Common\Resource\ProcessFactory;

/**
 * Class: DiagnosticsService handles support diagnostics.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class DiagnosticsService
{
    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory = null)
    {
        $this->processFactory = $processFactory ?: new ProcessFactory();
    }

    /**
     * Get all encrypted agents and corresponding loops / devmappers
     *
     * @return EncryptionList[]
     */
    public function getEncryptionList(): array
    {
        $devMappers = $this->getDevMappers();
        $encryptionList = [];
        foreach ($devMappers as $devMapper) {
            $loops = $this->getLoops($devMapper);
            $dettoFiles = $this->getDettoFiles($loops);
            $mountPoints = $this->getMountPoints($devMapper);
            $encryptionList[] = new EncryptionList($devMapper, $loops, $dettoFiles, $mountPoints);
        }
        return $encryptionList;
    }

    /**
     * Get all DevMappers
     *
     * @return string[]
     */
    private function getDevMappers(): array
    {
        $process = $this->processFactory->getFromShellCommandLine('dmsetup ls | awk \'{print$1}\'');
        $process->mustRun();
        $devMapperString = trim($process->getOutput());
        $devMappers = $devMapperString ? explode("\n", $devMapperString) : [];
        sort($devMappers);
        return $devMappers;
    }

    /**
     * Get all loops associated with a DevMapper
     *
     * @param string $devMapper Name of a DevMapper
     *
     * @return string[]
     */
    private function getLoops(string $devMapper): array
    {
        $process = $this->processFactory
            ->getFromShellCommandLine('lsblk | grep "${:DEVMAPPER}" -w -B2 | grep loop | awk \'{print $1}\'');
        $process->mustRun(null, ['DEVMAPPER' => $devMapper]);
        $loopString = trim($process->getOutput());
        $loops = $loopString ? explode("\n", $loopString) : [];
        $loops = preg_replace("/[^a-zA-Z0-9]/", "", $loops);
        sort($loops);
        return $loops;
    }

    /**
     * Get all .detto files associated with an array of loops
     *
     * @param array $loops array of loops
     *
     * @return array Associative array indexed by loop name with value equal to .detto file associated
     *    with the loop, e.g. array['loop1'] = /homePool/10.70.71.83-1507742299-file/532044768a4411e5824f806e6f6e6963.detto
     */
    private function getDettoFiles(array $loops): array
    {
        $dettoFiles = [];
        foreach ($loops as $loop) {
            $process = $this->processFactory
                ->getFromShellCommandLine('losetup -a | grep -w "${:LOOP}" | awk \'{print$3}\'');
            $process->mustRun(null, ['LOOP' => $loop]);
            $dettoFilesRawString = trim($process->getOutput());
            $dettoFilesRaw = $dettoFilesRawString ? explode("\n", $dettoFilesRawString) : [];
            sort($dettoFilesRaw);
            $dettoFilesPart = [];
            foreach ($dettoFilesRaw as $dettoFileRaw) {
                $dettoFilesPart[$loop] = preg_replace("/[()]/", "", $dettoFileRaw);
            }
            $merge = array_merge($dettoFiles, $dettoFilesPart);
            $dettoFiles = $merge;
        }
        return $dettoFiles;
    }

    /**
     * Get all mount points associated with a DevMapper
     *
     * @param string $devMapper Name of a DevMapper
     *
     * @return string[]
     */
    private function getMountPoints(string $devMapper): array
    {
        $process = $this->processFactory
            ->getFromShellCommandLine('mount | grep -w "${:DEVMAPPER}" | awk \'{print$3}\'');
        $process->mustRun(null, ['DEVMAPPER' => $devMapper]);
        $mountString = trim($process->getOutput());
        $mountPoints = $mountString ? explode("\n", $mountString) : [];
        sort($mountPoints);
        return $mountPoints;
    }
}
