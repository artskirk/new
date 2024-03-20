<?php

namespace Datto\License;

/**
 * Simple factory class for a ShadowProtectLicenseManager.
 *
 * @codeCoverageIgnore
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ShadowProtectLicenseManagerFactory
{
    /**
     * @param string $assetKey
     * @return ShadowProtectLicenseManager
     */
    public function create(string $assetKey): ShadowProtectLicenseManager
    {
        return new ShadowProtectLicenseManager($assetKey);
    }
}
