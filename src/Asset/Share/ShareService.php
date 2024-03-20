<?php

namespace Datto\Asset\Share;

use Datto\Asset\Asset;
use Datto\Asset\AssetServiceInterface;
use Datto\Asset\AssetType;
use Datto\Asset\LastErrorAlert;
use Datto\Asset\Repository;
use Datto\Cloud\JsonRpcClient;

/**
 * Service used to retrieve and persist shares (NAS/Samba as well as iSCSI shares).
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ShareService implements AssetServiceInterface
{
    /** @var Repository */
    private $repository;

    /** @var JsonRpcClient */
    private $client;

    public function __construct(
        ShareRepository $repository = null,
        JsonRpcClient $client = null
    ) {
        $this->repository = $repository ?: new ShareRepository();
        $this->client = $client ?: new JsonRpcClient();
    }

    /**
     * Stores a share on the disk using the repository provided in the constructor.
     *
     * @param Asset $share
     */
    public function save(Asset $share): void
    {
        $share->commit();
        $this->repository->save($share);

        if ($share->getLocal()->hasPausedChanged()) {
            $assets = [
                $share->getKeyName() => $share->getLocal()->isPaused()
            ];
            $this->client->notifyWithId('v1/device/asset/updatePaused', ['assets' => $assets]);
        }
    }

    /**
     * @return Share
     */
    public function get($shareName)
    {
        return $this->repository->get($shareName);
    }

    /**
     * @return Share[] list of shares on the device
     */
    public function getAll(string $type = null)
    {
        return $this->repository->getAll(true, true, $type ?? AssetType::SHARE);
    }

    /**
     * @return Share[] list of shares on the device. Not including replicated shares.
     */
    public function getAllLocal(string $type = null)
    {
        return $this->repository->getAll(false, true, $type ?? AssetType::SHARE);
    }

    /**
     * Get an array of asset keyNames.
     * This is significantly faster than calling getAll()
     *
     * @param string|null $type An AssetType or null for all shares
     * @return string[]
     */
    public function getAllKeyNames(string $type = null): array
    {
        return $this->repository->getAllNames(true, true, $type ?? AssetType::SHARE);
    }

    /**
     * @inheritdoc
     */
    public function exists($shareName)
    {
        return $this->repository->exists($shareName);
    }

    /**
     * Returns the last error of a share, or null if the error does not exist
     *  or is earlier than the latest snapshot epoch.
     *
     * @param Share $share
     * @return LastErrorAlert|null
     */
    public function getLastError(Share $share)
    {
        $lastSnapshot = $share->getLocal()->getRecoveryPoints()->getLast();
        $lastSnapshotDate = $lastSnapshot ? $lastSnapshot->getEpoch() : null;
        $lastError = $share->getLastError();

        if ($lastError && $lastError->getTime() < $lastSnapshotDate) {
            $lastError = null;
            $share->clearLastError();
            $this->save($share);
        }

        return $lastError;
    }
}
