<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;
use Datto\Ipmi\IpmiTool;

/**
 * Indicates that IPMI is supported
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class Ipmi extends Feature
{
    /** @var IpmiTool */
    private $ipmiTool;

    /**
     * @param IpmiTool $ipmiTool
     */
    public function __construct(IpmiTool $ipmiTool)
    {
        parent::__construct();

        $this->ipmiTool = $ipmiTool;
    }

    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL,
            DeviceRole::CLOUD
        ];
    }

    /** @inheritdoc */
    protected function checkDeviceConstraints()
    {
        try {
            // An exception will be thrown if we cannot contact IPMI through ipmitool
            $this->ipmiTool->getLan();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
