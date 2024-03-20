<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\Transfer;

/**
 * A serializer for serializing a list of transfers.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class TransfersSerializer implements Serializer
{
    /**
     * {@inheritdoc}
     */
    public function serialize($transfers)
    {
        $serializedTransfers = [];

        foreach ($transfers as $transfer) {
            /** @var Transfer $transfer */
            $serializedTransfers[] = $transfer->getSnapshotEpoch() . ':' . $transfer->getSize();
        }

        return implode("\n", $serializedTransfers);
    }

    /**
     * {@inheritdoc}
     */
    public function unserialize($serializedObject)
    {
        $transfers = [];

        $transferLines = explode("\n", trim($serializedObject));
        foreach ($transferLines as $transferLine) {
            $pieces = explode(":", $transferLine);

            $snapshotEpoch = $pieces[0] ?? null;
            $transferSize = $pieces[1] ?? null;

            if (isset($snapshotEpoch, $transferSize) &&
                ctype_digit($snapshotEpoch) &&
                ctype_digit($transferSize)) {
                $transfers[$snapshotEpoch] = new Transfer((int)$snapshotEpoch, (int)$transferSize);
            }
        }

        return $transfers;
    }
}
