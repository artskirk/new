<?php

namespace Datto\Asset\Serializer;

use Datto\Alert\Alert;

/**
 * Serializer for .alertConfig files
 *
 * @author Rixhers Ajazi <rajazi@datto.com>
 */
class LegacyAlertSerializer implements Serializer
{
    /**
     * @param Alert[] $alerts
     * @return string serialized array
     */
    public function serialize($alerts)
    {
        $alertArray = [];

        foreach ($alerts as $alert) {
            $tempAlertArray = [];
            $tempAlertArray['code'] = $alert->getCode();
            $tempAlertArray['message'] = $alert->getMessage();
            $tempAlertArray['firstSeen'] = $alert->getFirstSeen();
            $tempAlertArray['lastSeen'] = $alert->getLastSeen();
            $tempAlertArray['numberSeen'] = $alert->getNumberSeen();
            $tempAlertArray['user'] = $alert->getUser();
            $alertArray[] = $tempAlertArray;
        }

        return serialize($alertArray);
    }

    /**
     * Will be in the format:
     * array(code => , firstSeen => , lastSeen => , user => , numberSeen => , message => )
     *
     * @param string $serializedAlerts
     * @return Alert[] $alerts
     */
    public function unserialize($serializedAlerts)
    {
        $alerts = [];

        if ($serializedAlerts) {
            $unserializedAlerts = unserialize($serializedAlerts, ['allowed_classes' => false]);

            foreach ($unserializedAlerts as $alert) {
                $alerts[] = new Alert(
                    $alert['code'],
                    $alert['message'],
                    (int)$alert['firstSeen'],
                    (int)$alert['lastSeen'],
                    (int)$alert['numberSeen'],
                    $alert['user']
                );
            }
        }

        return $alerts;
    }
}
