<?php

namespace Datto\Connection\Service;

use Datto\Connection\Libvirt\AbstractLibvirtConnection;

/**
 * Minimal contract to which all final ConnectionServices need to adhere.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
interface ConnectionServiceInterface
{
    /**
     * @return mixed
     */
    public function connect(array $params);

    /**
     * @return bool true if successful
     */
    public function delete(AbstractLibvirtConnection $connection): bool;

    /**
     * @return bool true if exists
     */
    public function exists(string $name): bool;

    public function get(string $name): ?AbstractLibvirtConnection;

    /**
     * @return AbstractLibvirtConnection[]
     */
    public function getAll(): array;

    /**
     * Refresh and save all connection metadata (version etc.)
     */
    public function refreshAll(): void;

    public function getHypervisorOptions(int $hypervisorOption, array $params): array;

    /**
     * @return bool true if successful
     */
    public function save(AbstractLibvirtConnection $connection): bool;

    public function setConnectionParams(
        AbstractLibvirtConnection $connection,
        array $params
    ): AbstractLibvirtConnection;
}
