<?php

namespace Datto\Restore;

use Datto\Core\Storage\StorageInterface;
use Datto\ImageExport\ImageType;
use Datto\Log\LoggerAwareTrait;
use Datto\Restore\Export\Network\NetworkExportService;
use Exception;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;

/**
 * This class manages restore metadata (/datto/config/UIRestores)
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 * @author Jason Miesionczek <jmiesionczek@datto.com>
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class RestoreService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private RestoreRepository $repository;
    private NetworkExportService $networkExportService;
    private StorageInterface $storage;
    private RestoreFactory $restoreFactory;

    /** @var Restore[] */
    private array $restores;

    public function __construct(
        RestoreRepository $repository,
        NetworkExportService $networkExportService,
        StorageInterface $storageInterface,
        RestoreFactory $restoreFactory
    ) {
        $this->repository = $repository;
        $this->networkExportService = $networkExportService;
        $this->storage = $storageInterface;
        $this->restoreFactory = $restoreFactory;

        $this->restores = [];
    }

    /**
     * Get all the currently-active restores
     * @return Restore[]
     */
    public function getAll(): array
    {
        $this->restores = $this->repository->getAll();

        return $this->restores;
    }

    /**
     * Saves the UIRestores file
     */
    public function save()
    {
        $this->repository->save($this->restores);
    }

    /**
     * Add a new Restore
     *
     * @param Restore $restore
     * @return bool
     */
    public function add(Restore $restore): bool
    {
        if (isset($this->restores[$restore->getUiKey()])) {
            return false;
        }

        $this->restores[$restore->getUiKey()] = $restore;

        return true;
    }

    /**
     * @param Restore $restore
     * @return bool
     */
    public function existsInUi(Restore $restore): bool
    {
        if (empty($this->restores)) {
            $this->getAll();
        }
        return array_key_exists($restore->getUiKey(), $this->restores);
    }

    /**
     * @param Restore $restore
     */
    public function remove(Restore $restore)
    {
        // TODO: break out into its own endpoint
        if ($restore->getSuffix() == RestoreType::EXPORT) {
            $this->networkExportService->removeAll($restore->getAssetKey(), $restore->getPoint());
        }

        // Remove the restore metadata
        $this->deleteRestoreFromUi($restore);
    }

    /**
     * Delete a restore without performing any of the removal steps. (ie. 'only' delete it from the UIRestores file)
     *
     * @param Restore $restore
     */
    public function delete(Restore $restore)
    {
        // Remove the restore metadata
        $this->deleteRestoreFromUi($restore);
    }

    /**
     * Get the Restore for the given parameters and throw exception if it cannot be found
     */
    public function get(string $asset, string $point, string $suffix): Restore
    {
        $restore = $this->find($asset, $point, $suffix);
        if (is_null($restore)) {
            throw new RuntimeException("Restore for assetKey '$asset', point '$point', suffix '$suffix' does not exist.");
        }

        return $restore;
    }

    /**
     * Find the Restore for the given parameters and return null if it cannot be found
     */
    public function find(string $asset, string $point, string $suffix): ?Restore
    {
        $this->getForAsset($asset, [$suffix]);

        if (isset($this->restores[$asset . $point . $suffix])) {
            return $this->restores[$asset . $point . $suffix];
        }

        return null;
    }

    /**
     * Find the most recent restore with the given asset key and suffix
     */
    public function findMostRecent(string $asset, string $suffix): ?Restore
    {
        $restores = $this->getForAsset($asset, [$suffix]);
        usort($restores, function (Restore $a, Restore $b) {
            if ($a->getPoint() == $b->getPoint()) {
                return 0;
            }

            // sort by descending point
            return ($a->getPoint() > $b->getPoint()) ? -1 : 1;
        });

        // The first in the list will be the most recent
        return count($restores) === 0 ? null : $restores[0];
    }

    /**
     * @param Restore $restore
     * @return bool
     */
    public function update(Restore $restore): bool
    {
        if (isset($this->restores[$restore->getUiKey()])) {
            $this->restores[$restore->getUiKey()] = $restore;

            return true;
        }

        return false;
    }

    /**
     * Get a list of all the restores that exist in ZFS but not in the UI restores list
     * @return Restore[] list of orphaned restores, indexed by UI key (Restore->getUiKey())
     */
    public function getOrphans(): array
    {
        // more types can be added once we know how to un-orphan them
        $imageSuffixes = array(
            ImageType::VHD,
            ImageType::VHDX,
            ImageType::VMDK,
            ImageType::VMDK_LINKED
        );
        $restoreTypes = array_merge(array(
            'file',
            'active',
            'iscsi',
            'iscsimounter'
        ), $imageSuffixes);

        $zfsRestores = $this->getRestores($restoreTypes);
        $uiRestores = $this->getAll();
        $orphanedRestores = array();

        foreach ($zfsRestores as $zfsRestore) {
            if (!array_key_exists($zfsRestore->getUiKey(), $uiRestores)) {
                if (!in_array($zfsRestore->getSuffix(), $imageSuffixes)) {
                    $orphanedRestores[] = $zfsRestore;
                } else {
                    $agentName = $zfsRestore->getAssetKey();
                    $snapshotEpoch = $zfsRestore->getPoint();
                    $imageType = $zfsRestore->getSuffix();

                    try {
                        // we're dealing with an image, let's check if it's currently being exported
                        $status = $this->networkExportService->getStatus(
                            $agentName,
                            $snapshotEpoch,
                            ImageType::get($imageType)
                        );

                        // the export is in progress, don't mark as an orphan
                        if (!$status->isExporting()) {
                            $orphanedRestores[] = $zfsRestore;
                        }
                    } catch (Exception $ex) {
                        $this->logger->setAssetContext($agentName);
                        $this->logger->warning('RST0001 Unable to determine if restore was orphaned', ['exception' => $ex]);
                        // Something went wrong here and we can't really recover. Let's ignore it
                        // and continue collecting orphaned restores.
                    }
                }
            }
        }

        return $orphanedRestores;
    }

    /**
     * Retrieves a list of restores for an asset
     * @param string $assetName name of the asset to check for restores.
     * @param string[]|null $suffixes [optional] array to filter by suffix of restore
     * @return Restore[] returns array of restores.
     */
    public function getForAsset(string $assetName, array $suffixes = null): array
    {
        return $this->getAllForAssets([$assetName], $suffixes);
    }

    /**
     * Retrieves a list of restores for an optional set of assets and suffixes. In the absence of an option, that field
     * is not filtered and instead all restores for all assets and/or suffixes will be returned.
     * @param string[]|null $assetNames [optional] array of asset names to search on
     * @param string[]|null $suffixes [optional] array to filter by suffix of restores
     * @return Restore[]
     */
    public function getAllForAssets(array $assetNames = null, array $suffixes = null): array
    {
        $this->restores = $this->repository->getAll();
        return array_filter($this->restores, function ($restore) use ($suffixes, $assetNames) {
            return (!$assetNames || in_array($restore->getAssetKey(), $assetNames))
                && (!$suffixes || in_array($restore->getSuffix(), $suffixes));
        });
    }

    /**
     * @param string $connectionName for connection name
     * @return Restore[] returns active restores for connection
     */
    public function getActiveRestoresForConnection(string $connectionName): array
    {
        $activeRestores = array();
        $restores = $this->getAll();
        foreach ($restores as $restore) {
            $options = $restore->getOptions();
            if (isset($options['connectionName'])
                && $options['connectionName'] === $connectionName) {
                $activeRestores[] = $restore;
            }
        }
        return $activeRestores;
    }

    /**
     * Factory method for creating a new restore. This allows for calling code that creates new restores to
     * be unit-testable.
     *
     * This method returns a Restore instance, but the Restore is NOT persisted. For that, you must
     * call RestoreService->save(Restore);
     *
     * @deprecated Use the create method in RestoreFactory instead
     *
     * @param string $asset Name of the asset
     * @param string|int $point Snapshot timestamp
     * @param string $suffix
     * @param string|int $activationTime
     * @param array $options
     * @param string|null $html
     * @return Restore
     */
    public function create(
        $asset,
        $point,
        $suffix,
        $activationTime = null,
        $options = array(),
        $html = null
    ) {
        return $this->restoreFactory->create(
            $asset,
            $point,
            $suffix,
            $activationTime,
            $options,
            $html
        );
    }

    private function deleteRestoreFromUi(Restore $restore)
    {
        if ($this->existsInUi($restore)) {
            unset($this->restores[$restore->getUiKey()]);
        }
    }

    private function getRestores(array $restoreTypes): array
    {
        $storageIds = $this->storage->listClonedStorageIds('homePool');

        $restores = [];
        foreach ($storageIds as $storageId) {
            $storageInfo = $this->storage->getStorageInfo($storageId);
            $restore = $this->restoreFactory->createFromStorageInfo($storageInfo);
            if (isset($restore) && in_array($restore->getSuffix(), $restoreTypes)) {
                $restores[] = $restore;
            }
        }

        return $restores;
    }

    /**
     * @param Restore $restore
     * @return bool
     */
    public function isRemovableByUser(Restore $restore): bool
    {
        return !in_array($restore->getSuffix(), RestoreType::NON_USER_REMOVABLE_RESTORE_TYPES, true);
    }
}
