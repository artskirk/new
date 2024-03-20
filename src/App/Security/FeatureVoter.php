<?php

namespace Datto\App\Security;

use Datto\Feature\FeatureService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * This class handles the granting of access for any permission-security
 * related requests, such as is_granted(), that pages and actions may
 * require a different user role/permission to access.
 *
 * This voter only handles FEATURE_* permissions. It should be used with the
 * `@RequiresFeature` annotation.
 *
 * The $attribute variable is the permission string that is being
 * requested. The $subject variable is not currently used.
 *
 * The authorization decision is made by Symfony's Access Decision Maker
 * configured in `security.yaml`. It is configured to authorize requests
 * only if all voters (i.e. FeatureVoter and PermissionVoter) agree:
 *
 *   access_decision_manager:
 *     strategy: unanimous
 *     allow_if_all_abstain: false
 *
 * Do NOT remove:
 *   The class is automatically loaded and used by Symfony
 *   based on its `Voter` base class.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class FeatureVoter extends Voter
{
    /** @var FeatureService */
    private $featureService;

    /**
     * @param FeatureService $featureService
     */
    public function __construct(FeatureService $featureService)
    {
        $this->featureService = $featureService;
    }

    /**
     * Returns whether or not the permission ($attribute) is in the defined list of permissions.
     * If the permission is defined, it may be voted on.
     *
     * @param string $attribute the permission to check for support
     * @param mixed|null $subject the relevant variable or object pertaining to the permission
     * @return bool true if this voter supports the permission, false otherwise
     */
    public function supports($attribute, $subject = null)
    {
        return preg_match('/^FEATURE_/', $attribute);
    }

    /**
     * Returns whether or not the user is granted the permission, based on their roles.
     *
     * @param string $attribute the permission to vote on for granting permission
     * @param mixed|null $subject the relevant variable or object pertaining to the permission
     * @param TokenInterface $token the user's authentication token
     * @return bool
     */
    public function voteOnAttribute($attribute, $subject, TokenInterface $token)
    {
        return $this->featureService->isSupported($attribute);
    }
}
