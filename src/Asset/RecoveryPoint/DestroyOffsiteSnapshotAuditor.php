<?php

namespace Datto\Asset\RecoveryPoint;

use Datto\Cloud\JsonRpcClient;
use Datto\Config\AgentConfigFactory;
use Datto\Core\Network\DeviceAddress;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Report deletion requests to device-web for offsite snapshots.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class DestroyOffsiteSnapshotAuditor implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const UNKNOWN_TRANSFER_SIZE = -1;
    const UNKNOWN_SOURCE_ADDRESS = 'unknown';

    private JsonRpcClient $client;
    private AgentConfigFactory $agentConfigFactory;
    private DeviceAddress $deviceAddress;

    public function __construct(
        JsonRpcClient $client,
        AgentConfigFactory $agentConfigFactory,
        DeviceAddress $deviceAddress
    ) {
        $this->client = $client;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->deviceAddress = $deviceAddress;
    }

    /**
     * Report a request for destroying offsite snapshots.
     *
     * @param string $assetKey
     * @param int[] $snapshotEpochs
     * @param DestroySnapshotReason $sourceReason
     * @param string $sourceAddress
     */
    public function report(
        string $assetKey,
        array $snapshotEpochs,
        DestroySnapshotReason $sourceReason,
        string $sourceAddress = null
    ): void {
        $this->logger->setAssetContext($assetKey);

        $this->client->batch();
        $transferSizes = $this->getTransfersInformation($assetKey);

        foreach ($snapshotEpochs as $snapshotEpoch) {
            $transferSize = $transferSizes[$snapshotEpoch] ?? self::UNKNOWN_TRANSFER_SIZE;
            $sourceAddress = $sourceAddress ?? $this->getDefaultSourceAddress();

            $params = [
                'assetKey' => $assetKey,
                'snapshotEpoch' => $snapshotEpoch,
                'size' => $transferSize,
                'sourceReason' => $this->getSourceReasonString($sourceReason, false),
                'sourceAddress' => $sourceAddress
            ];

            $this->client->notifyWithId('v1/device/audit/offsite/snapshot/destroy/record', $params);
        }

        $snapshotEpochsString = implode(',', $snapshotEpochs);
        $this->logger->info('DSA0001 Notifying cloud of request to destroy offsite snapshots', [
            'snapshotEpochsString' => $snapshotEpochsString
        ]);
        $this->client->send();
    }

    /**
     * Report a request for purging offsite snapshots.
     *
     * @param string $assetKey
     * @param DestroySnapshotReason $sourceReason
     * @param string|null $sourceAddress
     */
    public function reportPurge(
        string $assetKey,
        DestroySnapshotReason $sourceReason,
        string $sourceAddress = null
    ): void {
        $this->logger->setAssetContext($assetKey);

        $sourceAddress = $sourceAddress ?? $this->getDefaultSourceAddress();

        $params = [
            'assetKey' => $assetKey,
            'sourceReason' => $this->getSourceReasonString($sourceReason, true),
            'sourceAddress' => $sourceAddress
        ];

        $this->logger->info('DSA0002 Notifying cloud of request to purge offsite snapshots');
        $this->client->notifyWithId('v1/device/audit/offsite/snapshot/destroy/recordPurge', $params);
    }

    /**
     * @param DestroySnapshotReason $sourceReason
     * @param bool $purge
     * @return string
     */
    private function getSourceReasonString(DestroySnapshotReason $sourceReason, bool $purge)
    {
        switch ($sourceReason) {
            case DestroySnapshotReason::MANUAL():
                $sourceReasonString = 'manual';
                break;
            case DestroySnapshotReason::RETENTION():
                $sourceReasonString = 'retention';
                break;
            default:
                // Famous last words: this case can never occur.
                throw new \Exception('Invalid source reason given for offsite deletion reporting: ' . $sourceReason->key());
        }

        if ($purge) {
            $sourceReasonString = $sourceReasonString . ' purge';
        }

        return $sourceReasonString;
    }

    /**
     * Ideally this class should belong on the asset class, but since it has the potential to grow quite large, we
     * would prefer that it is not loaded for every asset.
     *
     * @param string $assetKey
     * @return int[]
     */
    private function getTransfersInformation(string $assetKey)
    {
        $agentConfig = $this->agentConfigFactory->create($assetKey);
        $transferData = $agentConfig->get('transfers');

        $transfers = [];

        if ($transferData !== null) {
            foreach (explode("\n", trim($transferData)) as $snapshots) {
                /* Example line:
                 * 12345678:555
                 */

                list($time, $size) = explode(":", $snapshots);
                $transfers[(int)$time] = (int)$size;
            }
        }

        return $transfers;
    }

    /**
     * @return string
     */
    private function getDefaultSourceAddress()
    {
        $this->logger->info('DSA0003 Fetching default source address');

        try {
            $sourceAddress = $this->deviceAddress->getLocalIp();
            $this->logger->info('DSA0004 Found default source address', ['sourceAddress' => $sourceAddress]);
        } catch (\Throwable $e) {
            $this->logger->warning('DSA0005 Could not determine source address', ['exception' => $e]);
            $sourceAddress = self::UNKNOWN_SOURCE_ADDRESS;
        }

        return $sourceAddress;
    }
}
