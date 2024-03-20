<?php

namespace Datto\Log\Handler;

use Datto\Log\Formatter\DeviceFormatter;
use Datto\Log\LogRecord;
use Datto\Common\Utility\Filesystem;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Populates the device log file.
 *
 * @author Justin Giacobbi (justin@datto.com)
 */
class DeviceHandler extends AbstractProcessingHandler
{
    const DEVICE_LOG = "/var/log/datto/device.log";

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceFormatter */
    private $deviceFormatter;

    public function __construct(
        Filesystem $filesystem,
        DeviceFormatter $deviceFormatter,
        string $loggerLevel
    ) {
        parent::__construct($loggerLevel, true);

        $this->filesystem = $filesystem;
        $this->deviceFormatter = $deviceFormatter;
    }

    /**
     * Appends the formatted message to the appropriate log file.
     *
     * @param array $record
     */
    protected function write(array $record): void
    {
        $logRecord = new LogRecord($record);
        $this->filesystem->filePutContents(static::DEVICE_LOG, $logRecord->getFormatted(), FILE_APPEND);
    }

    /**
     * Please do not change this or set a new formatter.
     *
     * @return FormatterInterface
     */
    protected function getDefaultFormatter()
    {
        return $this->deviceFormatter;
    }
}
