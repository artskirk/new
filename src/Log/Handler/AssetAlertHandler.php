<?php

namespace Datto\Log\Handler;

use Datto\Alert\AlertManager;
use Datto\Log\Formatter\AbstractFormatter;
use Datto\Log\LogRecord;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Creates alerts if based on log messages (if necessary).
 *
 * Logs with a level _lower_ than INFO will be ignored.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 */
class AssetAlertHandler extends AbstractProcessingHandler
{
    /** @var AlertManager */
    private $alertManager;

    public function __construct(string $loggerLevel)
    {
        parent::__construct($loggerLevel, true);
    }

    /**
     * For unit tests
     * todo: inject AlertManager
     *
     * @param AlertManager $alertManager
     */
    public function setAlertManager(AlertManager $alertManager)
    {
        $this->alertManager = $alertManager;
    }

    /**
     * Set an alert based on the alert code severity.
     *
     * @param array $record
     */
    protected function write(array $record): void
    {
        $logRecord = new LogRecord($record);

        // Ignore alert codes with a low/zero severity
        if ($logRecord->getAlertSeverity() <= 0) {
            return;
        }

        if ($logRecord->hasAsset()) {
            $logKey = $logRecord->getAsset();
        } else {
            $logKey = AbstractFormatter::DEVICE_KEY;
        }

        $alertManager = $this->getAlertManager();
        $alertManager->processAlert(
            $logKey,
            $logRecord->getAlertCode(),
            $logRecord->getMessage(),
            $logRecord->getUser(),
            $logRecord->getContext()
        );
    }

    /**
     * This is bad but it prevents a few circular dependencies.
     * todo: inject AlertManager instead of creating a new instance
     * todo: This requires that AlertManager and its dependencies not use constructor injection for logging.
     *
     * @return AlertManager
     */
    private function getAlertManager(): AlertManager
    {
        if ($this->alertManager === null) {
            $this->alertManager = new AlertManager();
        }

        return $this->alertManager;
    }
}
