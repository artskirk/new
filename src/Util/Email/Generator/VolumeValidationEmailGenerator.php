<?php

namespace Datto\Util\Email\Generator;

use Datto\AppKernel;
use Datto\Asset\Agent\Agent;
use Datto\Asset\BackupConstraintsResult;
use Datto\Service\Networking\NetworkService;
use Datto\Util\Email\Email;

/**
 * Generates an email to be sent after volume validation fails on a DTC node.
 *
 * @author Huan-Yu Yih <hyih@datto.com>
 */
class VolumeValidationEmailGenerator
{
    const EMAIL_TYPE_VOLUME_VALIDATION_FAILURE = 'sendVolumeValidationFailure';
    const VOLUME_VALIDATION_SUBJECT = 'Volume Validation for %s on %s';

    private NetworkService $networkService;

    public function __construct(NetworkService $networkService = null)
    {
        $this->networkService = $networkService ?? AppKernel::getBootedInstance()->getContainer()->get(NetworkService::class);
    }

    /**
     * Generate the email
     * @param Agent $agent
     * @param BackupConstraintsResult $result
     * @return Email
     */
    public function generate(Agent $agent, BackupConstraintsResult $result): Email
    {
        $subject = sprintf(
            self::VOLUME_VALIDATION_SUBJECT,
            $agent->getDisplayName(),
            $this->networkService->getHostname()
        );
        $volumes = [];
        foreach ($agent->getVolumes() as $volume) {
            $volumes[] = [
                'mountpoint' => $volume->getMountpoint(),
                'spaceTotal' => $volume->getSpaceTotal()
            ];
        }
        $info = [
            'agent' => $agent->getPairName(),
            'failText' => $result->getMaxTotalVolumeMessage(),
            'type' => VolumeValidationEmailGenerator::EMAIL_TYPE_VOLUME_VALIDATION_FAILURE,
            'volumes' => $volumes
        ];
        $meta = [
            'hostname' => $agent->getKeyName()
        ];
        // this is a cloud continuity alert, so recipient email will be looked up
        return new Email('', $subject, $info, null, $meta);
    }
}
