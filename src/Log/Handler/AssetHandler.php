<?php

namespace Datto\Log\Handler;

use Datto\Log\CodeCounter;
use Datto\Log\Formatter\AssetFormatter;
use Datto\Log\LogRecord;
use Datto\Common\Utility\Filesystem;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Populates the asset-specific log files in KEYBASE.
 *
 * Logs with a level _lower_ than INFO will be ignored.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class AssetHandler extends AbstractProcessingHandler
{
    const KEYBASE = '/datto/config/keys';

    /** @var Filesystem */
    private $filesystem;

    /** @var AssetFormatter */
    private $assetFormatter;

    public function __construct(
        Filesystem $filesystem,
        AssetFormatter $assetFormatter,
        string $loggerLevel
    ) {
        parent::__construct($loggerLevel, true);

        $this->filesystem = $filesystem;
        $this->assetFormatter = $assetFormatter;
    }

    /**
     * Appends the formatted message to the appropriate log file.
     *
     * @param array $record
     */
    protected function write(array $record): void
    {
        $logRecord = new LogRecord($record);

        if (!$logRecord->hasAsset()) {
            return;
        }
        $assetKey = $logRecord->getAsset();

        $logPath = static::KEYBASE . '/' . $assetKey . '.log';
        $this->filesystem->filePutContents($logPath, $logRecord->getFormatted(), FILE_APPEND);

        $counter = CodeCounter::get($assetKey);
        if ($counter) {
            $counter->increment($logRecord->getLevel(), $logRecord->getAlertCode());
        }
    }

    /**
     * Please do not change this or set a new formatter.
     *
     * @return FormatterInterface
     */
    protected function getDefaultFormatter()
    {
        return $this->assetFormatter;
    }
}
