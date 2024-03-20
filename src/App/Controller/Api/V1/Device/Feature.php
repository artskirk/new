<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Feature\FeatureService;

/**
 * Provides API interface to FeatureService
 *
 * @author Justin Giacobbi <justin@datto.com>
 */
class Feature
{
    /** @var FeatureService */
    private $service;

    public function __construct(FeatureService $service)
    {
        $this->service = $service;
    }

    /**
     * List all features
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_FEATURES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NONE")
     *
     * @return array
     */
    public function listAll()
    {
        return array_values($this->service->listAll());
    }

    /**
     * Check if a feature is supported
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_FEATURES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NONE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "feature" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\_]+$~"),
     *   "assetName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $feature the feature in question
     * @param string $version Optional, requester passes in own version to check for compatibility
     * @param string $assetName Optional, check if a feature is enabled for a particular asset
     * @return array
     */
    public function isSupported($feature, $version = null, $assetName = null)
    {
        return ['supported' => $this->service->isSupported($feature, $version, $assetName)];
    }
}
