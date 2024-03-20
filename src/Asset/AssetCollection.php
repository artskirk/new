<?php

namespace Datto\Asset;

use ArrayObject;

/**
 * A wrapper around Asset[] with convenience search methods
 * @author Jason Lodice <jlodice@datto.com>
 */
class AssetCollection extends ArrayObject
{
    /**
     * @param Asset[]|null $assets
     */
    public function __construct(array $assets = null)
    {
        parent::__construct($assets ?? []);
    }

    /**
     * Find first asset with the given uuid.
     *
     * @param string $uuid asset identifier
     * @return Asset|null
     */
    public function selectByUuid(string $uuid)
    {
        foreach ($this as $asset) {
            if ($asset->getUuid() === $uuid) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Filter current collection for replicated assets, return them in a new collection.
     *
     * @return AssetCollection
     */
    public function whereIsReplicated(): AssetCollection
    {
        /** @var Asset $asset */
        foreach ($this as $asset) {
            if ($asset->getOriginDevice()->isReplicated()) {
                $assets[] = $asset;
            }
        }

        return new AssetCollection($assets ?? []);
    }

    /**
     * Filter current collection for assets which do not have one of the given uuids, return then in a new collection.
     *
     * @param array $uuids
     * @return AssetCollection
     */
    public function exceptUuid(array $uuids) : AssetCollection
    {
        /** @var Asset $asset */
        foreach ($this as $asset) {
            if (!in_array($asset->getUuid(), $uuids)) {
                $assets[] = $asset;
            }
        }
        return new AssetCollection($assets ?? []);
    }
}
