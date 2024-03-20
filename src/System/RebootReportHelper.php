<?php

namespace Datto\System;

use Datto\Events\EventService;
use Datto\Events\RebootEventFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Common\Utility\Filesystem;
use Psr\Log\LoggerAwareInterface;

/**
 * Helper class to manage reboot-specific files and reporting
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class RebootReportHelper implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const CAUSE_DEVICE = 'device';
    const CAUSE_UNKNOWN = 'unknown';
    const CAUSE_UPGRADECTL = 'upgradectl';

    const REBOOT_CAUSE_FILE = '/datto/config/local/rebootCause';
    const REBOOT_CLEAN_FLAG_FILE = '/datto/config/local/rebootCleanFlag';

    /** @var Collector */
    private $collector;

    /** @var EventService */
    private $eventService;

    /** @var Filesystem */
    private $filesystem;

    /** @var RebootEventFactory */
    private $rebootEventFactory;

    public function __construct(
        Collector $collector,
        EventService $eventService,
        Filesystem $filesystem,
        RebootEventFactory $rebootEventFactory
    ) {
        $this->collector = $collector;
        $this->eventService = $eventService;
        $this->filesystem = $filesystem;
        $this->rebootEventFactory = $rebootEventFactory;
    }

    public function causedBy(string $cause): bool
    {
        $allowedCauses = [self::CAUSE_DEVICE, self::CAUSE_UNKNOWN, self::CAUSE_UPGRADECTL];

        if (!in_array($cause, $allowedCauses)) {
            $this->logger->warning('DRE0301 Ignoring invalid cause of reboot', ['cause' => $cause]);

            return false;
        }

        $this->logger->debug('DRE0101 Setting the cause of reboot', ['cause' => $cause]);

        return $this->causedIfFirst($cause);
    }

    public function causedByDevice(): bool
    {
        return $this->causedBy(self::CAUSE_DEVICE);
    }

    public function markAsClean()
    {
        // wording on this log is definite as the method is called during reboot/shutdown
        $this->logger->debug('DRE0102 Marking existing reboot as clean');
        $this->filesystem->touch(self::REBOOT_CLEAN_FLAG_FILE);
    }

    public function report()
    {
        $this->logger->debug('DRE0103 Preparing to report reboot event');
        $cause = $this->getCause();
        $wasClean = $this->filesystem->exists(self::REBOOT_CLEAN_FLAG_FILE);
        $rebootEvent = $this->rebootEventFactory->create($wasClean, $cause);
        $this->eventService->dispatch($rebootEvent, $this->logger);
        $this->unlinkIfExists(self::REBOOT_CAUSE_FILE);
        $this->unlinkIfExists(self::REBOOT_CLEAN_FLAG_FILE);
        $this->collector->increment(Metrics::SYSTEM_REBOOT);
    }

    /**
     * Write to cause file if it is a first time (file does not exist)
     */
    private function causedIfFirst(string $cause): bool
    {
        if ($this->filesystem->exists(self::REBOOT_CAUSE_FILE)) {
            $this->logger->debug('DRE0104 File exists, preserving existing cause file');

            return false;
        }

        $this->logger->debug('DRE0105 Writting to reboot cause file', ['cause' => $cause]);
        $bytesWritten = $this->filesystem->filePutContents(self::REBOOT_CAUSE_FILE, $cause);

        if ($bytesWritten === false) {
            $this->logger->warning('DRE0302 Failed to write to reboot file', ['file' => self::REBOOT_CAUSE_FILE]);
        }

        return $bytesWritten !== false;
    }

    private function getCause(): string
    {
        $cause = self::CAUSE_UNKNOWN;

        if ($this->filesystem->isReadable(self::REBOOT_CAUSE_FILE)) {
            $cause = $this->filesystem->fileGetContents(self::REBOOT_CAUSE_FILE);
        }

        return $cause;
    }

    private function unlinkIfExists(string $file)
    {
        if ($this->filesystem->exists($file)) {
            $this->logger->debug('DRE0106 Removing file', ['file' => $file]);
            $this->filesystem->unlink($file);
        }
    }
}
