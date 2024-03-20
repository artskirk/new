<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Replication\ReplicationService;

/**
 * API endpoint for authorized devices.
 *
 * @author Jack Corrigan <jcorrigan@datto.com>
 */
class PeerReplication
{
    /** @var ReplicationService */
    private $replicationService;

    public function __construct(
        ReplicationService $replicationService
    ) {
        $this->replicationService = $replicationService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_PEER_REPLICATION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_PEER_REPLICATION")
     * @return array
     */
    public function getAuthorizedTargets()
    {
        $targets = [];
        foreach ($this->replicationService->getAuthorizedTargets() as $target) {
            $targets[] = ['id' => $target['deviceID'], 'hostname' => $target['hostname']];
        }

        return $targets;
    }
}
