<?php

namespace Datto\Feature\Features;

use Datto\Backup\SecondaryReplicationService;
use Datto\Feature\Context;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;
use Exception;

/**
 * Determines if the device supports secondary replication.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class OffsiteSecondary extends Feature
{
    /** @var SecondaryReplicationService */
    private $replicationService;

    /**
     * @param string|null $name
     * @param Context|null $context
     */
    public function __construct(
        string $name = null,
        Context $context = null,
        SecondaryReplicationService $replicationService = null
    ) {
        parent::__construct($name, $context);
        $this->replicationService = $replicationService ?: new SecondaryReplicationService();
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

    /**
     * {@inheritdoc}
     */
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        $potentiallySupported = !$deviceConfig->isAlto();

        if ($potentiallySupported) {
            try {
                return $this->replicationService->isAvailable(); // FIXME This calls speedsync without caching. Fix me!
            } catch (Exception $e) {
                return false;
            }
        } else {
            return false;
        }
    }
}
