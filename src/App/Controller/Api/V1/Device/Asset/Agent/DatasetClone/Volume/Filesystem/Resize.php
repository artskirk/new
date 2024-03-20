<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent\DatasetClone\Volume\Filesystem;

use Datto\App\Controller\Api\V1\Device\Asset\Agent\AbstractAgentEndpoint;
use Datto\Asset\Agent\AgentService;
use Datto\Filesystem\Resize\ResizeFactory;

/**
 * API endpoint for resizing a filesystem in an Agent's cloned dataset.
 */
class Resize extends AbstractAgentEndpoint
{
    /** @var ResizeFactory */
    private $resizeFactory;

    public function __construct(
        AgentService $agentService,
        ResizeFactory $resizeFactory
    ) {
        parent::__construct($agentService);
        $this->resizeFactory = $resizeFactory;
    }

    /**
     * Calculate an images minimum size
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "digit"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr|differential\-rollback~")
     * })
     * @param string $agent name of agent
     * @param string $snapshot name of snapshot
     * @param string $extension extension of clone
     * @param string $guid guid of image file
     * @return int[]
     *  Array may contain any of the following keys
     *  Array (
     *      'minSize' =>      109090
     *      'clusterSize' =>  100
     *      'originalSize' => 512
     *  )
     *
     * @deprecated BCDR-25568 this method can time out and break the restore. Use the asynchronous calculateMinimumSizeStart/calculateMinimumSizeStatus/calculateMinimumSizeStop methods instead.
     */
    public function calculateMinimumSize($agent, $snapshot, $extension, $guid)
    {
        $resizeObj = $this->resizeFactory->getResizeObject($agent, $snapshot, $extension, $guid);
        set_time_limit($resizeObj::TIMEOUT);
        $result = $resizeObj->calculateMinimumSize();
        return $result;
    }

    /**
     * Resize an Image
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr|differential\-rollback~")
     * })
     * @param string $agent Name of the agent
     * @param string $snapshot Name of the snapshot
     * @param string $extension Extension to use for path to image resize
     * @param string $guid unique identified for image file being resized
     * @return bool $result of call to launch resize, false on failure
     */
    public function start($agent, $snapshot, $extension, $guid, $targetSize)
    {
        $resizeObj = $this->resizeFactory->getResizeObject($agent, $snapshot, $extension, $guid);
        $result = $resizeObj->resizeToSize($targetSize);
        return $result;
    }

    /**
     * Fetch status for current running resize operation
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr|differential\-rollback~")
     * })
     * @param string $agent the agent that is being resized
     * @param string $snapshot the snapshot for the agent that is being resized
     * @param string $extension the extension for the zfs clone path for the agent that is being resized
     * @param string $guid global unique identifier for image file that is being resized for agent/snapshot/extension
     * @return mixed[] $result of status call array('running', 'percent', 'stage', 'stderr')
     */
    public function getStatus($agent, $snapshot, $extension, $guid)
    {
        $resizeObj = $this->resizeFactory->getResizeObject($agent, $snapshot, $extension, $guid);
        $resizeProgress = $resizeObj->getResizeProgress();
        return $resizeProgress->toArray();
    }

    /**
     * Kill a screen running a resize
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr|differential\-rollback~")
     * })
     * @param string $agent string agent to halt
     * @param string $snapshot  snapshot to halt
     * @param string $extension  extension to halt
     * @param $guid string guid of image file being resized to halt
     * @return mixed[]
     */
    public function stop($agent, $snapshot, $extension, $guid)
    {
        $resizeObj = $this->resizeFactory->getResizeObject($agent, $snapshot, $extension, $guid);
        $result = $resizeObj->stopResize();
        return array("screendead" => $result);
    }

    /**
     * start an async Calculate an images minimum size, just like resize does
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr|differential\-rollback~"),
     *   "volumeUuid" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agent id of the agent
     * @param string $snapshot Name of the snapshot
     * @param string $extension Extension to use for path to image resize
     * @param string $volumeUuid unique identified for image file being resized
     * @return bool $result of call to launch calculate size, false on failure
     *
     * @throws \Exception
     */
    public function calculateMinimumSizeStart(string $agent, string $snapshot, string $extension, string $volumeUuid): bool
    {
        $resizeObj = $this->resizeFactory->getResizeObject($agent, $snapshot, $extension, $volumeUuid);
        $result = $resizeObj->calculateMinimumSizeStart();
        return $result;
    }

    /**
     * Fetch status for current running calcminsize operation
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr|differential\-rollback~"),
     *   "volumeUuid" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agent the agent that is being resized
     * @param string $snapshot the snapshot for the agent that is being resized
     * @param string $extension the extension for the zfs clone path for the agent that is being resized
     * @param string $volumeUuid global unique identifier for image file that is being resized for agent/snapshot/extension
     * @return mixed[] $result of status call array('running', 'percent', 'stage', 'stderr')
     * @throws \Exception
     */
    public function calculateMinimumSizeGetStatus(string $agent, string $snapshot, string $extension, string $volumeUuid): array
    {
        $resizeObj = $this->resizeFactory->getResizeObject($agent, $snapshot, $extension, $volumeUuid);
        $resizeProgress = $resizeObj->calculateMinimumSizeGenerateProgressObject();
        return $resizeProgress->toArray();
    }

    /**
     * Kill a screen running a calcminsize
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr|differential\-rollback~"),
     *   "volumeUuid" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agent string agent to halt
     * @param string $snapshot snapshot to halt
     * @param string $extension extension to halt
     * @param $volumeUuid string guid of image file being calcminsize'd
     * @return array
     * @throws \Exception
     */
    public function calculateMinimumSizeStop(string $agent, string $snapshot, string $extension, string $volumeUuid): array
    {
        $resizeObj = $this->resizeFactory->getResizeObject($agent, $snapshot, $extension, $volumeUuid);
        $result = $resizeObj->calculateMinimumSizeStop();
        return array("screendead" => $result);
    }
}
