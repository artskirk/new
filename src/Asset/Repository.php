<?php

namespace Datto\Asset;

interface Repository
{
    /**
     * Returns whether a model exists for the specified name
     *
     * @param string $keyName
     * @param string|null $type
     * @return bool
     */
    public function exists($keyName, $type = null);

    /**
     * Stores a model
     *
     * @param Asset $asset
     */
    public function save(Asset $asset);

    /**
     * Destroys the model for a particular share
     *
     * @param $name
     */
    public function destroy($name);

    /**
     * Retrieves a model
     *
     * @param $name
     * @return Asset
     */
    public function get($name);

    /**
     * Retrieve all share models
     *
     * @param bool $getReplicated Whether replicated assets will be included
     * @param bool $getArchived Whether archived assets will be included
     * @param string|null $type An AssetType constant. Only get assets that match this type.
     * @return mixed
     */
    public function getAll(bool $getReplicated, bool $getArchived, ?string $type);
}
