<?php
namespace Datto\Log;

use Exception;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;

/**
 * Build syslog logger objects
 */
class LogBuilder
{
    /**
     * @var string $name
     *   The name for the logging channel.
     */
    private $name;

    /**
     * @var string $ident
     *   A string of text to identify messages.
     *   This value is prepended to syslog messages.
     *   Can be used to route messages in rsyslog configuration files.
     */
    private $ident;

    /**
     * @var string $format
     *   The format string for the log messages.
     *   Must use Monolog's LineFormatter syntax.
     *   For example, Monolog's default format is:
     *     '[%datetime%] %channel%.%level_name%: %message% %context% %extra%'
     */
    private $format = "%channel%.%level_name%: %message% %context% %extra%\n";

    /**
     * Get the syslog logger object.
     */
    public function getLogger()
    {
        if ($this->name === null) {
            throw new Exception('Logger name is not set');
        }

        if ($this->ident === null) {
            throw new Exception('Logger ident is not set');
        }

        $syslog = new SyslogHandler($this->ident);
        $syslog->setFormatter(new LineFormatter($this->format, null, true, true));
        $handlers = array($syslog);

        return new Logger($this->name, $handlers);
    }

    /**
     * Set the name of the logging channel.
     *
     * @param $name
     *   The name for the logging channel.
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set the identifier to prepend to syslog messages.
     *
     * @param $ident
     *   A string of text to identify messages.
     *   This value is prepended to syslog messages.
     *   Can be used to route messages in rsyslog configuration files.
     *
     * @return $this
     */
    public function setIdent($ident)
    {
        $this->ident = $ident;
        return $this;
    }

    /**
     * Set the format for the log message.
     *
     * @param $format
     *   The format string for the log messages.
     *   Must use Monolog's LineFormatter syntax.
     *   For example, Monolog's default format is:
     *     '[%datetime%] %channel%.%level_name%: %message% %context% %extra%'
     *
     * @return $this
     */
    public function setFormat($format)
    {
        $this->format = $format;
        return $this;
    }
}
