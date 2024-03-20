<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Share\Iscsi;

use Datto\App\Controller\Api\V1\Device\Asset\Share\AbstractShareEndpoint;
use Datto\Asset\Share\Iscsi\ChapSettings;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Log\SanitizedException;
use Exception;
use Throwable;

/**
 * Endpoint to enable/disable CHAP Authentication for a iscsi share.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Peter Ruczynski <pjr@datto.com>
 */
class Chap extends AbstractShareEndpoint
{
    /**
     * Add a user to a share
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_ISCSI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="iscsi"),
     *   "username" = {
     *      @Symfony\Component\Validator\Constraints\NotBlank(),
     *      @Symfony\Component\Validator\Constraints\Length(max="100"),
     *      @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[[:alnum:].:-]+$~")
     *   },
     *   "password" = @Symfony\Component\Validator\Constraints\AtLeastOneOf(
     *       @Symfony\Component\Validator\Constraints\Blank(),
     *       @Symfony\Component\Validator\Constraints\Length(min=12, max=16)
     *   ),
     *   "enableMutual" = @Symfony\Component\Validator\Constraints\Type(type = "boolean"),
     *   "mutualUsername" = {
     *      @Symfony\Component\Validator\Constraints\Length(max="100"),
     *      @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[[:alnum:].:-]+$~")
     *   },
     *   "mutualPassword" = @Symfony\Component\Validator\Constraints\AtLeastOneOf(
     *       @Symfony\Component\Validator\Constraints\Blank(),
     *       @Symfony\Component\Validator\Constraints\Length(min=12, max=16)
     *   )
     * })
     * @param string $shareName
     * @param string $username
     * @param string $password
     * @param string $enableMutual
     * @param string $mutualUsername
     * @param string $mutualPassword
     * @return array
     */
    public function enable($shareName, $username, $password, $enableMutual, $mutualUsername, $mutualPassword)
    {
        try {
            /** @var IscsiShare $share */
            $share = $this->shareService->get($shareName);
            $chapSettings = $share->getChap();
            $chapSettings->enable($username, $password, $enableMutual, $mutualUsername, $mutualPassword);
            $this->shareService->save($share);
            return true;
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES_ISCSI")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "shareName" = @Datto\App\Security\Constraints\AssetExists(type="iscsi"),
     * })
     * @param string $shareName
     * @return bool
     */
    public function disable($shareName)
    {
        /** @var IscsiShare $share */
        $share = $this->shareService->get($shareName);
        $chapSettings = $share->getChap();

        $existingMutualUser = $chapSettings->getMutualUser();
        if ($existingMutualUser !== ChapSettings::DEFAULT_MUTUAL_USER) {
            $chapSettings->removeMutualUser($existingMutualUser);
        }

        $existingUser = $chapSettings->getUser();
        if ($existingUser !== ChapSettings::DEFAULT_USER) {
            $chapSettings->removeUser($existingUser);
        }

        $this->shareService->save($share);

        return true;
    }
}
