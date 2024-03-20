<?php
namespace Datto\Asset;

interface AssetServiceInterface
{
    /**
     * Retrieve an existing Asset
     *
     * @param string $name Name of the asset
     * @return Asset The requested asset
     */
    public function get($name);

    /**
     * Retrieve all Asset objects of the relevant asset type configured on this device.
     *
     * Note:
     *   Invalid assets will be ignored by this method.
     *
     * @return Asset[] List of Asset objects
     */
    public function getAll();

    /**
     * Checks to see if an asset exists
     *
     * @return bool True if it exists, false otherwise
     */
    public function exists($name);
}
