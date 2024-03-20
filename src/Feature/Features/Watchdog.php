<?php

namespace Datto\Feature\Features;

use Datto\Feature\Context;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;
use Datto\Feature\FeatureService;

/**
 * Determines if the device supports the watchdog feature.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class Watchdog extends Feature
{
    /** @var FeatureService */
    private $featureService;

    /**
     * @param string|null $name
     * @param Context|null $context
     * @param FeatureService|null $featureService
     */
    public function __construct(
        string $name = null,
        Context $context = null,
        FeatureService $featureService = null
    ) {
        parent::__construct($name, $context);
        $this->featureService = $featureService ?: new FeatureService();
    }

    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }

    /** @inheritdoc */
    protected function checkDeviceConstraints()
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_IPMI);
    }
}
