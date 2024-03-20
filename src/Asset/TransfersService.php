<?php

namespace Datto\Asset;

use Datto\Asset\Serializer\TransfersSerializer;
use Datto\Config\AgentConfigFactory;
use Datto\ZFS\ZfsService;

/**
 * Manages the "transfers" key file, which tracks the number of bytes transferred during each backup.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class TransfersService
{
    const TRANSFERS_KEY = 'transfers';

    /** @var TransfersSerializer */
    private $serializer;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var ZfsService */
    private $zfsService;

    /** @var AssetService */
    private $assetService;

    /**
     * @param TransfersSerializer $serializer
     * @param AgentConfigFactory $agentConfigFactory
     * @param ZfsService $zfsService
     * @param AssetService $assetService
     */
    public function __construct(
        TransfersSerializer $serializer,
        AgentConfigFactory $agentConfigFactory,
        ZfsService $zfsService,
        AssetService $assetService
    ) {
        $this->serializer = $serializer;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->zfsService = $zfsService;
        $this->assetService = $assetService;
    }

    /**
     * Generate any missing transfer entries. The value will be pulled from zfs' "used" property and won't be
     * totally accurate.
     *
     * @param string $assetKey
     */
    public function generateMissing(string $assetKey): void
    {
        $asset = $this->assetService->get($assetKey);
        $usedSizes = $this->zfsService->getSnapshotUsedSizes($asset->getDataset()->getZfsPath());
        $transfers = $this->get($assetKey);

        foreach ($usedSizes as $snapshotEpoch => $usedSize) {
            if (!isset($transfers[$snapshotEpoch])) {
                $transfers[$snapshotEpoch] = new Transfer($snapshotEpoch, $usedSize);
            }
        }

        $this->save($assetKey, $transfers);
    }

    /**
     * @param string $assetKey
     * @return Transfer[]
     */
    public function get(string $assetKey): array
    {
        $serialized = $this->agentConfigFactory->create($assetKey)->get(self::TRANSFERS_KEY);
        $transfers = $this->serializer->unserialize($serialized);

        return $transfers;
    }

    /**
     * @param string $assetKey
     * @param Transfer $transfer
     */
    public function add(string $assetKey, Transfer $transfer): void
    {
        $transfers = $this->get($assetKey);
        $transfers[$transfer->getSnapshotEpoch()] = $transfer;
        $this->save($assetKey, $transfers);
    }

    /**
     * @param string $assetKey
     * @param Transfer[] $transfers
     */
    public function save(string $assetKey, array $transfers): void
    {
        uasort($transfers, function (Transfer $transfer1, Transfer $transfer2) {
            return $transfer1->getSnapshotEpoch() <=> $transfer2->getSnapshotEpoch();
        });

        $serialized = $this->serializer->serialize($transfers);
        $this->agentConfigFactory->create($assetKey)->set(self::TRANSFERS_KEY, $serialized);
    }
}
