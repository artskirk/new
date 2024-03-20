<?php

namespace Datto\Log;

/**
 * Code counter for tracking log codes counts.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class CodeCounter
{
    private static $instances = [];

    /** @var array */
    private $counts;

    public function __construct()
    {
        $this->counts = [];
    }

    /**
     * Increment a code with a given severity.
     *
     * @param int $severity
     * @param string $code
     */
    public function increment($severity, $code)
    {
        if (!isset($this->counts[$severity][$code])) {
            $this->counts[$severity][$code] = 0;
        }
        $this->counts[$severity][$code]++;
    }

    /**
     * Get all counts.
     *
     * Example:
     *      increment(Logger::DEBUG, "BAK0001");
     *      increment(Logger::DEBUG, "BAK0001");
     *
     *      getAll() === [
     *          Logger::DEBUG => [
     *              "BAK0001" => 2
     *          ]
     *      ]
     *
     * @return array|int[]
     */
    public function getAll()
    {
        return $this->counts;
    }

    /**
     * @param string $assetKey
     * @return CodeCounter
     */
    public static function get(string $assetKey)
    {
        if (!isset(self::$instances[$assetKey])) {
            self::$instances[$assetKey] = new CodeCounter();
        }

        return self::$instances[$assetKey];
    }
}
