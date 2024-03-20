<?php

namespace Datto\Service\CloudManagedConfig;

use Datto\Config\DeviceState;
use Datto\Resource\DateTimeService;

class CloudManagedConfigState
{
    const CONFIG_UPDATED_AT = 'configUpdatedAt'; // epoch of when user requested local config change
    const CONFIG_APPLIED_AT = 'configAppliedAt'; // epoch of when config was applied (local or cloud)

    private DeviceState $deviceState;
    private DateTimeService $dateTimeService;

    public function __construct(
        DeviceState $deviceState,
        DateTimeService $dateTimeService
    ) {

        $this->deviceState = $deviceState;
        $this->dateTimeService = $dateTimeService;
    }

    public function setAppliedAt(): void
    {
        $this->deviceState->set(self::CONFIG_APPLIED_AT, $this->dateTimeService->getTime());
    }

    public function setUpdatedAt(int $epoch = null): void
    {
        if ($epoch === null) {
            $epoch = $this->dateTimeService->getTime();
        }

        $this->deviceState->set(self::CONFIG_UPDATED_AT, $epoch);
    }

    public function getAppliedAt(): int
    {
        if (!$this->deviceState->has(self::CONFIG_APPLIED_AT)) {
            $this->deviceState->set(self::CONFIG_APPLIED_AT, $this->dateTimeService->getTime());
        }

        return $this->deviceState->get(self::CONFIG_APPLIED_AT);
    }

    public function getUpdatedAt(): int
    {
        return $this->deviceState->get(
            self::CONFIG_UPDATED_AT,
            $this->getAppliedAt()
        );
    }
}
