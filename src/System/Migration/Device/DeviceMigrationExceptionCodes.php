<?php

namespace Datto\System\Migration\Device;

/**
 * Exceptions codes that may be used during device migrations.
 *
 * @author Chris McGehee <cmcgehee@datto.com>
 */
class DeviceMigrationExceptionCodes
{
    // These have matching consts in web/js/App/Advanced/AdvancedStatusView.js.
    const MINIMUM_SOURCE_VERSION = 701;
    const NOT_AUTHORIZED_DEVICEWEB = 702;
}
