<?php

namespace Datto\System\Migration\Serialization;

use Datto\Asset\Serializer\Serializer;
use Datto\System\Migration\AbstractMigration;
use Datto\System\Migration\MigrationFactory;

/**
 * Serializes and unserializes Migration objects.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class MigrationSerializer implements Serializer
{
    /** @var MigrationFactory */
    private $migrationFactory;

    /**
     * @param MigrationFactory $migrationFactory
     */
    public function __construct(MigrationFactory $migrationFactory)
    {
        $this->migrationFactory = $migrationFactory;
    }

    /**
     * Serialize a migration.
     *
     * @param AbstractMigration $migration
     * @return string
     */
    public function serialize($migration)
    {
        return json_encode([
            'type' => $migration->getType(),
            'migration' => $migration
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Unserialize a migration.
     *
     * @param string $serializedMigration
     * @return AbstractMigration
     */
    public function unserialize($serializedMigration)
    {
        $migrationArray = json_decode($serializedMigration, true);
        $migration = $this->migrationFactory->createMigrationFromString($migrationArray['type']);
        $migration->populateFromArray($migrationArray['migration']);

        return $migration;
    }
}
