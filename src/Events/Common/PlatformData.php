<?php

namespace Datto\Events\Common;

use Datto\Events\AbstractEventNode;

/**
 * Device platform details
 */
class PlatformData extends AbstractEventNode
{
    /** @var string device model, E.G. S3B2000 or S1000 */
    protected $model;

    /** @var int IBU image version */
    protected $imageVersion;

    /** @var string kernel version */
    protected $kernelVersion;

    /** @var string IRIS package (datto-siris-os-2) version */
    protected $irisVersion;

    /** @var string device location (partner, Cloud, Azure) */
    protected $deviceRole;

    /** @var string device environment (dev, qa, prod) */
    protected $environment;

    /** @var string Geographic region served by hosting datacenter (e.g. eastus) */
    protected $datacenterRegion;

    public function __construct(
        string $model,
        int $imageVersion,
        string $kernelVersion,
        string $irisVersion,
        string $deviceRole,
        string $environment,
        string $datacenterRegion
    ) {
        $this->model = $model;
        $this->imageVersion = $imageVersion;
        $this->kernelVersion = $kernelVersion;
        $this->irisVersion = $irisVersion;
        $this->deviceRole = $deviceRole;
        $this->environment = $environment;
        $this->datacenterRegion = $datacenterRegion;
    }
}
