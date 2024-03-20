<?php

namespace Datto\App\Controller\Api\V1\Device\Roundtrip;

use Datto\Utility\Roundtrip\RoundtripStatus;

/**
 * Defines interface and helpers common to both USB and NAS Roundtrip API endpoints.
 *
 * @author Afeique Sheikh <asheikh@datto.com>
 */
abstract class AbstractRoundtrip
{
    /**
     * Should return an array containing the Roundtrip status.
     *
     * @return array
     */
    abstract public function getStatus(): array;

    /**
     * Should cancel the currently running Roundtrip.
     *
     * @return bool
     */
    abstract public function cancel(): bool;

    /**
     * Helper to convert a RoundtripStatus object to an array for an API endpoint.
     *
     * @param RoundtripStatus $status
     * @return array
     */
    protected function statusToArray(RoundtripStatus $status): array
    {
        $result = [
            'type' => $status->getType(),
            'running' => $status->isRunning(),
            'lastFinished' => $status->getLastFinished(),
            'lastState' => $status->getLastState(),
        ];

        if ($status->isRunning()) {
            $result['currentTotal'] = $status->getCurrentTotal();
            $result['currentStage'] = $status->getCurrentStage();
        }

        if ($status->getSpeed() !== null) {
            $result['speed'] = $status->getSpeed();
            $result['percent'] = $status->getPercent();
            $result['timeLeft'] = $status->getTimeLeft();
            $result['totalSize'] = $status->getTotalSize();
            $result['totalComplete'] = $status->getTotalComplete();
        }

        return $result;
    }
}
