<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Share\Nas;

use Datto\App\Controller\Api\V1\Device\Asset\Share\AbstractShareEndpoint;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\ShareService;
use Datto\Core\Network\WindowsDomain;

/**
 * Endpoint to add/remove users for a share.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class User extends AbstractShareEndpoint
{
    private WindowsDomain $windowsDomain;

    /**
     * Define a regex for invalid characters in user/group names, to be used for Symfony validation.
     * We don't *really* need to do this, because we check all usernames against the list of actual users before
     * adding/removing, but it doesn't hurt to filter out names we know for sure are invalid.
     *
     * Windows
     *  - https://docs.microsoft.com/en-us/previous-versions/windows/it-pro/windows-2000-server/bb726984(v=technet.10)
     *  - Names cannot contain `" / \ [ ] : ; | = , + * ? < >`
     *  - However, our users are in the format DOMAIN\user, so allow the backslash
     *  - PHP won't let us use the `"` literal in Doc annotations, so use the `\x22` character code instead
     * Linux
     *  - Usernames are much more restrictive, and can only contain a-z, A-Z, 0-9, and `.`, `-`, and `_`, so we have
     *    to use the Windows rules for validation
     */
    public const INVALID_CHARS = "~[\[\]/:;|=,+*?<>\x22]~";

    public function __construct(ShareService $shareService, WindowsDomain $windowsDomain)
    {
        parent::__construct($shareService);
        $this->windowsDomain = $windowsDomain;
    }

    /**
     * Add a user to a share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *   "userName" = @Symfony\Component\Validator\Constraints\Regex(match = false, pattern = User::INVALID_CHARS),
     *   "asAdmin" = @Symfony\Component\Validator\Constraints\Type(type = "boolean")
     * })
     * @param string $shareName Name of the share
     * @param string $userName Name of the user to add
     * @param bool $asAdmin True if user should have admin access, false otherwise
     * @return array
     */
    public function add($shareName, $userName, $asAdmin = false)
    {
        /** @var NasShare $share */
        $share = $this->shareService->get($shareName);

        $share->getUsers()->add($userName, $asAdmin);
        $this->shareService->save($share);

        return array(
            'user' => $userName,
            'access' => $asAdmin,
            'domain' => $this->windowsDomain->inDomain()
        );
    }

    /**
     * Add a group to a share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *   "groupName" = @Symfony\Component\Validator\Constraints\Regex(match = false, pattern = User::INVALID_CHARS),
     * })
     * @param string $shareName Name of the share
     * @param $groupName
     * @return array
     * @internal param string $userName Name of the user to add
     */
    public function addGroup($shareName, $groupName)
    {
        /** @var NasShare $share */
        $share = $this->shareService->get($shareName);

        $share->getUsers()->addGroup($groupName);
        $this->shareService->save($share);

        return array(
            'group' => $groupName
        );
    }

    /**
     * Remove a user from a share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *   "userName" = @Symfony\Component\Validator\Constraints\Regex(match = false, pattern = User::INVALID_CHARS),
     * })
     * @param string $shareName Name of the share
     * @param string $userName Name of the user to add
     * @return array
     */
    public function remove($shareName, $userName)
    {
        /** @var NasShare $share */
        $share = $this->shareService->get($shareName);

        $share->getUsers()->remove($userName);
        $this->shareService->save($share);

        return array(
            'userName' => $userName
        );
    }

    /**
     * Remove a group from a share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *   "groupName" = @Symfony\Component\Validator\Constraints\Regex(match = false, pattern = User::INVALID_CHARS),
     * })
     * @param string $shareName Name of the share
     * @param string $groupName Name of the group
     * @return array
     */
    public function removeGroup($shareName, $groupName)
    {
        /** @var NasShare $share */
        $share = $this->shareService->get($shareName);

        $share->getUsers()->removeGroup($groupName);
        $this->shareService->save($share);

        return array(
            'group' => $groupName
        );
    }

    /**
     * Grant admin access to a user for a share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *   "userName" = @Symfony\Component\Validator\Constraints\Regex(match = false, pattern = User::INVALID_CHARS),
     * })
     * @param string $shareName Name of the share
     * @param string $userName Name of the user to add
     * @return bool
     */
    public function grantAdmin($shareName, $userName)
    {
        /** @var NasShare $share */
        $share = $this->shareService->get($shareName);

        $share->getUsers()->setAdmin($userName, true);
        $this->shareService->save($share);

        return true;
    }

    /**
     * Revoke admin access to a user for a share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_NAS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="nas"),
     *   "userName" = @Symfony\Component\Validator\Constraints\Regex(match = false, pattern = User::INVALID_CHARS),
     * })
     * @param string $shareName Name of the share
     * @param string $userName Name of the user to add
     * @return bool
     */
    public function revokeAdmin($shareName, $userName)
    {
        /** @var NasShare $share */
        $share = $this->shareService->get($shareName);

        $share->getUsers()->setAdmin($userName, false);
        $this->shareService->save($share);

        return true;
    }
}
