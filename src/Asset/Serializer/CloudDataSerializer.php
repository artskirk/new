<?php
namespace Datto\Asset\Serializer;

use Datto\Asset\Asset;
use Datto\Asset\VerificationSchedule;
use Datto\Asset\Serializer\Serializer;
use Exception;

/**
 * Class CloudDataSerializer
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 * @author John Fury Christ <jchrists@datto.com>
 */
class CloudDataSerializer implements Serializer
{
     /**
     * @param Asset $asset
     * @return array
     */
    public function serialize($asset)
    {
        /** @var  Asset $asset */
        $isCustomSchedule = $asset->getVerificationSchedule()->getScheduleOption() ===
            VerificationSchedule::CUSTOM_SCHEDULE;
        $getCustomSchedule = $asset->getVerificationSchedule()->getCustomSchedule();
        $cloudData = array(
            'keyName' => $asset->getKeyName(),
            'localInterval' => $asset->getLocal()->getInterval(),
            'localSchedule' => $asset->getLocal()->getSchedule()->getSchedule(),
            'localRetention' => array(
                'daily' => $asset->getLocal()->getRetention()->getDaily(),
                'weekly' => $asset->getLocal()->getRetention()->getWeekly(),
                'monthly' => $asset->getLocal()->getRetention()->getMonthly(),
                'keep' => $asset->getLocal()->getRetention()->getMaximum(),
            ),
            'offsiteReplication' => $asset->getOffsite()->getReplication(),
            'offsiteSchedule' => $asset->getOffsite()->getSchedule()->getSchedule(),
            'offsiteRetention' => array(
                'daily' => $asset->getOffsite()->getRetention()->getDaily(),
                'weekly' => $asset->getOffsite()->getRetention()->getWeekly(),
                'monthly' => $asset->getOffsite()->getRetention()->getMonthly(),
                'keep' => $asset->getOffsite()->getRetention()->getMaximum(),
            ),
            'verificationScheduleType' => $asset->getVerificationSchedule()->getScheduleOption(),
            'verificationCustomSchedule' => $isCustomSchedule ? $getCustomSchedule->getSchedule() : null,
        );

        return $cloudData;
    }

    /**
     * Not implemented.
     *
     * @param mixed $serializedObject dummy parameter
     * @return void
     */
    public function unserialize($serializedObject)
    {
        throw new Exception('Placeholder function');
    }
}
