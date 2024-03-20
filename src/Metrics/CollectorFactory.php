<?php

namespace Datto\Metrics;

use Datto\Metrics\Collector\Telegraf;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class CollectorFactory
{
    const HOST = '127.0.0.1';
    const PORT = '8125';
    const PREFIX = 'device.';
    const AUTOFLUSH = true;

    private static ?Collector $collector = null;

    public static function create(): Collector
    {
        if (!self::$collector) {
            self::$collector = new Telegraf(self::HOST, self::PORT, self::PREFIX, self::AUTOFLUSH);
        }

        return self::$collector;
    }
}
