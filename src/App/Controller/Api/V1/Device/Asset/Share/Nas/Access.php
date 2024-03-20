<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Share\Nas;

use Datto\Asset\Share\Nas\NasShare;
use Datto\App\Controller\Api\V1\Device\Asset\Share\AbstractShareEndpoint;
use Datto\Asset\Share\ShareService;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Exception;

/**
 * This class contains the API endpoints for setting the
 * access level and write access level for NAS shares.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * @author John Roland <jroland@datto.com>
 */
class Access extends AbstractShareEndpoint
{
    const ACCESS_ERROR_CODE_RESTORES_EXIST_FOR_SHARE = 200;
    const ACCESS_ERROR_MSG_RESTORES_EXIST_FOR_SHARE = 'Unable to set authorized user on share(s) with active restores';

    private RestoreService $restoreService;

    public function __construct(
        ShareService $shareService,
        RestoreService $restoreService
    ) {
        parent::__construct($shareService);
        $this->restoreService = $restoreService;
    }

    /**
     * Change the access permission of the share to be either public or private.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *   "accessLevel" = @Symfony\Component\Validator\Constraints\Choice(choices = { "public", "private" })
     * })
     * @param string $shareName Name of the share.
     * @param string $accessLevel Access level of share [public|private]
     * @return array an array of shareName and accessLevel
     */
    public function setLevel(string $shareName, string $accessLevel): array
    {
        $share = $this->shareService->get($shareName);
        if (!$share instanceof NasShare) {
            throw new Exception('This asset type is not supported. This method only applies to NAS shares.');
        }

        $share->getAccess()->setLevel($accessLevel);
        $this->shareService->save($share);

        return array(
            'shareName' => $shareName,
            'accessLevel' => $accessLevel
        );
    }

    /**
     * Change the write access of the share to be either creator or all.
     * creator set the default permissions to write for creator only.
     * all sets the default permissions to write for all users and changes all existing files to be write for all users
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *   "writeAccessLevel" = @Symfony\Component\Validator\Constraints\Choice(choices = { "creator", "all" })
     * })
     * @param string $shareName Name of the share.
     * @return array an array of shareName and writeLevel
     */
    public function setWriteLevel(string $shareName, string $writeAccessLevel): array
    {
        $share = $this->shareService->get($shareName);
        if (!$share instanceof NasShare) {
            throw new Exception('This asset type is not supported. This method only applies to NAS shares.');
        }

        $share->getAccess()->setWriteLevel($writeAccessLevel);
        $this->shareService->save($share);

        return array(
            'shareName' => $shareName,
            'writeLevel' => $writeAccessLevel
        );
    }

    /**
     * Get the write access of the share.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     * })
     * @param string $shareName Name of the share to be created.
     * @return array an array of shareName and writeLevel [creator|all]
     */
    public function getWriteLevel(string $shareName): array
    {
        $share = $this->shareService->get($shareName);
        if (!$share instanceof NasShare) {
            throw new Exception('This asset type is not supported. This method only applies to NAS shares.');
        }

        return array(
            'shareName' => $shareName,
            'writeLevel' => $share->getAccess()->getWriteLevel()
        );
    }

    /**
     * Sets an authorized user, which will have access to Samba share during a file restore of this share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *   "authorizedUser" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_]+$~")
     * })
     * @param string $shareName Name of the share
     * @param string $authorizedUser name of the user to grant secure access
     * @return array an array of shareName and authorizerUser
     */
    public function setAuthorizedUser(string $shareName, string $authorizedUser): array
    {
        // Cannot set an authorized user if active file restores exist. Check for any before continuing.
        $restores = $this->restoreService->getForAsset($shareName, [RestoreType::FILE]);
        if (count($restores) > 0) {
            throw new Exception(self::ACCESS_ERROR_MSG_RESTORES_EXIST_FOR_SHARE, self::ACCESS_ERROR_CODE_RESTORES_EXIST_FOR_SHARE);
        }

        $share = $this->shareService->get($shareName);
        if (!$share instanceof NasShare) {
            throw new Exception('This asset type is not supported. This method only applies to NAS shares.');
        }

        $share->getAccess()->setAuthorizedUser($authorizedUser);
        $this->shareService->save($share);

        return array(
            'shareName' => $shareName,
            'authorizedUser' => $authorizedUser
        );
    }

    /**
     * Sets an authorized user, which will have access to Samba share during a file restore of all existing shares
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "authorizedUser" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_]+$~")
     * })
     * @param string $authorizedUser Name of the user to set
     * @return array list of all shares that were changed, contains shareName and authorizedUser
     */
    public function setAuthorizedUserAll(string $authorizedUser): array
    {
        // Cannot set an authorized user if active file restores exist. To help make this API call atomic for
        // all shares, check for any active file restores on all shares before continuing.
        $shares = $this->shareService->getAll();
        $assetKeys = array_map(function ($share) {
            return $share->getKeyName();
        }, $shares);
        if (count($assetKeys) === 0) {
            return [];
        }
        $restores = $this->restoreService->getAllForAssets($assetKeys, [RestoreType::FILE]);
        if (count($restores) > 0) {
            throw new Exception(self::ACCESS_ERROR_MSG_RESTORES_EXIST_FOR_SHARE, self::ACCESS_ERROR_CODE_RESTORES_EXIST_FOR_SHARE);
        }

        $status = array();
        foreach ($shares as $share) {
            if (!($share instanceof NasShare)) {
                continue;
            }

            $share->getAccess()->setAuthorizedUser($authorizedUser);
            $this->shareService->save($share);

            $status[] = array(
                'shareName' => $share->getKeyName(),
                'authorizedUser' => $authorizedUser
            );
        }
        return $status;
    }
}
