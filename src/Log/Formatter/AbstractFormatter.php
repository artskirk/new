<?php

namespace Datto\Log\Formatter;

use Datto\Log\LoggerHelperTrait;
use Datto\Log\LogRecord;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Logger;

/**
 * Code reuse dumpster
 *
 * @author Justin Giacobbi (justin@datto.com)
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
abstract class AbstractFormatter extends NormalizerFormatter implements FormatterInterface
{
    use LoggerHelperTrait;

    /*
     * ANSI Escape Codes
     */
    const BLUE = "\x1B[1;34m";
    const DARK_BLUE = "\x1B[34m";
    const CYAN = "\x1B[1;36m";
    const DARK_CYAN = "\x1B[36m";
    const GREEN = "\x1B[1;32m";
    const DARK_GREEN = "\x1B[32m";
    const GREY = "\x1B[1;30m";
    const DARK_GREY = "\x1B[30m";
    const MAGENTA = "\x1B[1;35m";
    const RED = "\x1B[1;31m";
    const DARK_RED = "\x1B[31m";
    const YELLOW = "\x1B[1;33m";
    const CLOSE = "\x1B[0m";

    const ALERT_CODE_LENGTH = 7;
    const CONTEXT_ID_LENGTH = 8;
    const FILENAME_MAX_LENGTH = 32;
    const LOG_LEVEL_MAX_LENGTH = 4;
    const USER_MAX_LENGTH = 12;

    const SPENCERCUBE_LINE = " : eval()'d code";
    const SPENCERCUBE_LINE_DRIFT = 2;

    const PREFIX = "...";

    const DEVICE_KEY = "device-general";

    const DATE_FORMAT_NO_TIMEZONE = 'y-m-d H:i:s';
    const DATE_FORMAT_WITH_TIMEZONE = 'y-m-d H:i:sP';

    public function __construct(
        string $dateFormat = self::DATE_FORMAT_NO_TIMEZONE
    ) {
        parent::__construct($dateFormat);
    }

    /**
     * @return int
     */
    protected function agentNameMaxLength()
    {
        return strlen(static::DEVICE_KEY);
    }

    /**
     * @param LogRecord $logRecord
     * @return int
     */
    protected function prefaceLength(LogRecord $logRecord): int
    {
        $length = 0;

        $dateTime = $logRecord->getDateTime();
        if (isset($dateTime)) {
            $length += strlen($dateTime) + 1;
        }

        $length += (static::ALERT_CODE_LENGTH + 1)
            + (static::FILENAME_MAX_LENGTH + 1)
            + (static::LOG_LEVEL_MAX_LENGTH + 1)
            + (static::CONTEXT_ID_LENGTH + 1)
            + $this->agentNameMaxLength();

        return $length;
    }

    /**
     * @param int $level
     * @return array
     */
    protected function getBacktraceItem(int $level): array
    {
        $backtrace = @debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $level + 1);
        return $backtrace[$level];
    }

    /**
     * @param int $level
     * @return string
     */
    protected function getLevelColor(int $level): string
    {
        if ($level <= Logger::DEBUG) {
            return static::GREY;
        } elseif ($level <= Logger::INFO) {
            return static::CYAN;
        } elseif ($level <= Logger::WARNING) {
            return static::YELLOW;
        } else {
            return static::DARK_RED;
        }
    }

    /**
     * @param string $file
     * @param string|int $line
     * @return string
     */
    protected function formatBacktrace(string $file, $line): string
    {
        if (strpos($file, static::SPENCERCUBE_LINE) !== false) {
            $file = str_replace(static::SPENCERCUBE_LINE, "", $file);
            $line += static::SPENCERCUBE_LINE_DRIFT;
        }

        $file = str_replace('.php', '', $file);
        $couplet = $this->formatToLength("$file:$line", static::FILENAME_MAX_LENGTH);

        list($file, $line) = explode(":", $couplet);

        $couplet =
            static::BLUE . $file . static::CLOSE . ":" .
            static::MAGENTA . $line . static::CLOSE;

        return $couplet;
    }

    /**
     * @param int $level
     * @return string
     */
    protected function formatLevel(int $level): string
    {
        $name = substr(Logger::getLevelName($level), 0, static::LOG_LEVEL_MAX_LENGTH);
        $color = $this->getLevelColor($level);
        return $color . $name . static::CLOSE;
    }

    /**
     * @param string $date
     * @return string
     */
    protected function formatDate(string $date): string
    {
        return static::DARK_GREEN . $date . static::CLOSE;
    }

    /**
     * @param string $code
     * @return string
     */
    protected function formatAlertCode(string $code): string
    {
        return static::DARK_CYAN . $code . static::CLOSE;
    }

    /**
     * Format the channel string for color and size
     *
     * @param string $channel the channel string in the log record
     * @return string $string
     */
    protected function formatChannel(string $channel): string
    {
        $channel = $this->formatToLength(
            $channel,
            $this->agentNameMaxLength(),
            STR_PAD_LEFT
        );

        return static::RED . $channel . static::CLOSE;
    }

    protected function formatContextId(string $contextId): string
    {
        return $this->formatToLength($contextId, static::CONTEXT_ID_LENGTH);
    }

    /**
     * @param string $user
     * @return string
     */
    protected function formatUser(string $user): string
    {
        return static::DARK_BLUE .
            $this->formatToLength($user, static::USER_MAX_LENGTH) .
            static::CLOSE;
    }

    /**
     * @param string $string
     * @param int $length
     * @param int $pad
     * @return string
     */
    protected function formatToLength(string $string, int $length, int $pad = STR_PAD_RIGHT): string
    {
        if (strlen($string) > $length) {
            $length = $length - strlen(static::PREFIX);
            return static::PREFIX . substr($string, strlen($string) - $length);
        } else {
            return str_pad($string, $length, " ", $pad);
        }
    }

    /**
     * @param string $message
     * @param int $prefaceLength
     * @return string
     */
    protected function formatMessage(
        string $message,
        int $prefaceLength
    ): string {
        $parts = array_filter(explode("\n", $message));

        if (count($parts) > 1) {
            $message = array_shift($parts);

            // +1 is for a space between the preface and the message
            $preface = str_repeat(" ", $prefaceLength + 1);

            foreach ($parts as $part) {
                $message .= PHP_EOL . $preface . $part;
            }
        }

        return $message;
    }

    /**
     * Returns a colored, structured version of the context variables.
     *
     * This converts objects, arrays, etc. to strings and displays it
     * in a YAML-style format. It also indents the context correctly.
     *
     * @param array $vars
     * @param int $prefaceLength
     * @return string
     */
    protected function formatContext(array $vars, int $prefaceLength): string
    {
        if (count($vars['context']) > 0) {
            $context = $this->getContextWithHiddenMetadataRemoved($vars['context']);

            $stringifiedContext = $this->stringify($context);
            if ($stringifiedContext) {
                $context = $this->indentify($stringifiedContext, $prefaceLength - 1);
                $contextWithLabel = sprintf(
                    self::GREY . "%{$prefaceLength}s" . self::CLOSE . " %s",
                    "CTXT",
                    ltrim($context)
                );
                return $contextWithLabel;
            }
        }

        return '';
    }

    /**
     * Convert any variable into a string and format and indent
     * it in a human readable format.
     *
     * @param mixed $data
     * @return string
     */
    private function stringify($data): string
    {
        if (null === $data || is_bool($data)) {
            return var_export($data, true);
        } elseif (is_string($data) && $this->isMultiLine($data)) {
            return $this->indentify($data, 2);
        } elseif (is_scalar($data)) {
            return (string) $data;
        } elseif (is_array($data)) {
            $output = "";
            foreach ($data as $key => $value) {
                $strValue = $this->stringify($value);
                $output .= "  $key: ";

                if ($this->isMultiLine($strValue)) {
                    $output .= "\n" . $this->indentify(rtrim($strValue), 2) . "\n";
                } else {
                    $output .= "$strValue\n";
                }
            }
            return $output;
        } else {
            return $this->toJson($data, true);
        }
    }

    /**
     * Indent a multi-line string with spaces.
     *
     * @param string $s
     * @param int $indent
     * @return string
     */
    private function indentify($s, $indent): string
    {
        $output = array();
        $lines = explode("\n", $s);

        foreach ($lines as $line) {
            $output[] = str_repeat(' ', $indent) . $line;
        }

        while (count($output) > 0) {
            $lastLine = $output[count($output) - 1];

            if (trim($lastLine) === '') {
                array_pop($output);
            } else {
                break;
            }
        }

        return implode("\n", $output);
    }

    /**
     * Determine if a string is multi line.
     *
     * @param string $s
     * @return bool
     */
    private function isMultiLine($s): bool
    {
        return substr_count($s, "\n") > 0;
    }
}
