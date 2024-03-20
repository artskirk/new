<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Verification\VerificationService;

/**
 * Endpoint for controlling screenshot verification settings.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ScreenshotVerification
{
    /** @var VerificationService */
    private $verificationService;

    public function __construct(
        VerificationService $verificationService
    ) {
        $this->verificationService = $verificationService;
    }

    /**
     * Set the expected applications for an asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresFeature("FEATURE_APPLICATION_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @param string $agentKey Agent ID
     * @param string[] $applicationIds
     * @return bool
     */
    public function setExpectedApplications(string $agentKey, array $applicationIds): bool
    {
        $this->verificationService->setExpectedApplications($agentKey, $applicationIds);

        return true;
    }

    /**
     * Get the expected applications for an asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresFeature("FEATURE_APPLICATION_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @param string $agentKey
     * @return string[]
     */
    public function getExpectedApplications(string $agentKey): array
    {
        return [
            'expectedApplications' => $this->verificationService->getExpectedApplications($agentKey)
        ];
    }

    /**
     * Set the expected services for an asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresFeature("FEATURE_APPLICATION_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Datto\App\Security\Constraints\AssetExists(),
     *   "serviceIds" = {
     *     @Symfony\Component\Validator\Constraints\All(
     *          @Symfony\Component\Validator\Constraints\NotBlank(),
     *          @Symfony\Component\Validator\Constraints\Type(type = "string")
     *     ),
     *   },
     * })
     * @param string $agentKey
     * @param string[] $serviceIds Expected service IDs
     * @return bool
     */
    public function setExpectedServices(string $agentKey, array $serviceIds): bool
    {
        $this->verificationService->setExpectedServices($agentKey, $serviceIds);

        return true;
    }

    /**
     * Get the expected services for an asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresFeature("FEATURE_APPLICATION_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @param string $agentKey
     * @return string[] Expected service IDs
     */
    public function getExpectedServices(string $agentKey): array
    {
        return [
            'expectedServices' => array_keys($this->verificationService->getExpectedServices($agentKey))
        ];
    }
}
