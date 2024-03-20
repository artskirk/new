<?php

namespace Datto\Service\Restore\Export;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Utility\Filesystem\AbstractFuseOverlayMount;
use Datto\ImageExport\BootType;
use Datto\ImageExport\ImageType;
use Datto\Restore\Export\Context;
use Datto\Restore\Export\Serializers\StatusSerializer;

/**
 * Factory for creation of Context's that can be injected for unit tests.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class ContextFactory
{
    public function create(
        Agent $agent,
        int $snapshot,
        ImageType $type,
        AbstractFuseOverlayMount $fuseOverlayMount,
        bool $enableAgentInRestoredVm,
        BootType $bootType = null,
        Filesystem $filesystem = null,
        StatusSerializer $statusSerializer = null,
        AgentSnapshotService $agentSnapshotService = null
    ): Context {
        return new Context(
            $agent,
            $snapshot,
            $type,
            $fuseOverlayMount,
            $enableAgentInRestoredVm,
            $bootType,
            $filesystem,
            $statusSerializer,
            $agentSnapshotService
        );
    }
}
