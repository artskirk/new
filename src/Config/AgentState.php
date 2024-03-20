<?php

namespace Datto\Config;

/**
 * Settings for the state of a specified agent
 *
 * @author Shawn Carpenter <scarpenter@datto.com>
 */
class AgentState extends AgentFileConfig
{
    const BASE_KEY_CONFIG_PATH = '/var/lib/datto/device/agent';

    public const KEY_SCREENSHOT_FAILED = 'screenshotFailed';
    public const KEY_AUTOREPAIR_RETRYCOUNT = 'autorepair.retryCount';
    public const KEY_SCREENSHOT_SKIPPED = 'screenshotSkipped';
}
