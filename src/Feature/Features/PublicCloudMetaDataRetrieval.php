<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Feature which controls whether or not to run the backup transaction that retrieves meta data from the public cloud
 * for a given device and agent.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudMetaDataRetrieval extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::AZURE
        ];
    }

    protected function checkDeviceConstraints()
    {
        return $this->context->getDeviceConfig()->isAzureModel();
    }
}
