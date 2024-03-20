<?php

namespace Datto\Log\Handler;

use Datto\Log\Formatter\ConsoleFormatter;
use Datto\Log\LogRecord;
use Datto\Common\Utility\Filesystem;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Logs events to stdout/console.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 * @author Justin Giacobbi (justin@datto.com)
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class DeviceConsoleHandler extends AbstractProcessingHandler
{
    /** @var Filesystem */
    private $filesystem;

    /** @var ConsoleFormatter */
    private $consoleFormatter;

    public function __construct(
        Filesystem $filesystem,
        ConsoleFormatter $consoleFormatter,
        string $loggerLevel,
        $bubble = true
    ) {
        parent::__construct($loggerLevel, $bubble);
        $this->filesystem = $filesystem;
        $this->consoleFormatter = $consoleFormatter;
    }

    /**
     * Prints the formatted message.
     *
     * @param array $record
     */
    protected function write(array $record): void
    {
        if (PHP_SAPI === 'cli') {
            $logRecord = new LogRecord($record);
            $this->filesystem->write(STDERR, $logRecord->getFormatted());
        }
    }

    /**
     * Please do not change this or set a new formatter.
     *
     * @return FormatterInterface
     */
    protected function getDefaultFormatter()
    {
        return $this->consoleFormatter;
    }
}
