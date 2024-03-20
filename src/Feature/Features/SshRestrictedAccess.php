<?php

namespace Datto\Feature\Features;

use Datto\App\Console\Command\Device\PrepareDeviceCommand;
use Datto\Config\DeviceConfig;
use Datto\Feature\Context;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;
use Datto\Utility\Azure\InstanceMetadata;

/**
 * Use restricted SSH configuration.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class SshRestrictedAccess extends Feature
{
    /** @var InstanceMetadata */
    private $instanceMetadata;

    public function __construct(
        InstanceMetadata $instanceMetadata,
        string $name = null,
        Context $context = null
    ) {
        parent::__construct($name, $context);

        $this->instanceMetadata = $instanceMetadata;
    }

    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::CLOUD,
            DeviceRole::AZURE
        ];
    }

    /**
     * @inheritdoc
     */
    protected function checkDeviceConstraints()
    {
        $isDevDevice = false;

        // Remove restrictions on SSH for non-production azure devices for a better development workflow
        // This should be removed in the future if dev/staging Azure devices get public IPs accessible through RLY
        if ($this->context->getDeviceConfig()->isAzureDevice() && $this->instanceMetadata->isSupported()) {
            $tags = $this->instanceMetadata->getTags();

            if (isset($tags[PrepareDeviceCommand::DEVICEWEB_HOST_TAG])) {
                $isDevDevice = true;
            }
        }

        // Remove restrictions on SSH for dev cloud devices
        if ($this->context->getDeviceConfig()->isCloudDevice() &&
            $this->context->getDeviceConfig()->getDeploymentEnvironment() === DeviceConfig::DEV_DEPLOYMENT_ENVIRONMENT
        ) {
            $isDevDevice = true;
        }

        return !$isDevDevice;
    }
}
