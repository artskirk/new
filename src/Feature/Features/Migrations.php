<?php

namespace Datto\Feature\Features;

use Datto\Feature\Context;
use Datto\Feature\Feature;

/**
 * Generic 'Migrations' feature.
 * Devices that support either device migrations or storage upgrades will support this.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class Migrations extends Feature
{
    /** @var DeviceMigration */
    private $deviceMigration;

    /** @var StorageUpgrade */
    private $storageUpgrade;

    /**
     * @param string|null $name
     * @param Context|null $context
     * @param DeviceMigration|null $deviceMigration
     * @param StorageUpgrade|null $storageUpgrade
     */
    public function __construct(
        string $name = null,
        Context $context = null,
        DeviceMigration $deviceMigration = null,
        StorageUpgrade $storageUpgrade = null
    ) {
        parent::__construct($name, $context);
        $this->deviceMigration = $deviceMigration ?: new DeviceMigration(null, $context);
        $this->storageUpgrade = $storageUpgrade ?: new StorageUpgrade(null, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function checkDeviceConstraints()
    {
        return $this->deviceMigration->isSupported() || $this->storageUpgrade->isSupported();
    }

    /**
     * {@inheritdoc}
     */
    public function setContext(Context $context)
    {
        $this->context = $context;
        $this->deviceMigration->setContext($context);
        $this->storageUpgrade->setContext($context);
    }
}
