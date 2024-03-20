<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Share\Nas;

use Datto\App\Controller\Api\V1\Device\Asset\Share\AbstractShareEndpoint;
use Datto\Asset\Share\Nas\NasShare;
use Exception;

/**
 * Endpoint to enable/disable SFTP for a NAS share.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 */
class Sftp extends AbstractShareEndpoint
{
    /**
     * Enable SFTP for a share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas")
     * })
     * @param string $shareName Name of the share
     * @return array
     */
    public function enable($shareName)
    {
        $share = $this->shareService->get($shareName);
        if (!$share instanceof NasShare) {
            throw new Exception('This asset type is not supported. This method only applies to NAS shares.');
        }

        $share->getSftp()->enable();
        $this->shareService->save($share);

        return array(
            'shareName' => $shareName,
            'status' => 'enabled'
        );
    }

    /**
     * Disable SFTP for a share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas")
     * })
     * @param string $shareName Name of the share
     * @return array
     */
    public function disable($shareName)
    {
        $share = $this->shareService->get($shareName);
        if (!$share instanceof NasShare) {
            throw new Exception('This asset type is not supported. This method only applies to NAS shares.');
        }

        $share->getSftp()->disable();
        $this->shareService->save($share);

        return array(
            'shareName' => $shareName,
            'status' => 'disabled'
        );
    }
}
