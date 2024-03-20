<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent\DatasetClone\Volume;

use Datto\App\Controller\Api\V1\Device\Asset\Agent\AbstractAgentEndpoint;
use Datto\Asset\Agent\AgentService;
use Datto\Common\Utility\Filesystem;
use Datto\Resource\DateTimeService;

class Transfer extends AbstractAgentEndpoint
{
    const POOL_PATH = '/homePool/';
    const SUFFIX_DATTO = '.datto';
    const TIMEOUT_FILE = 'bmrTimeout';
    const KEY_PATH_BASE = '/datto/config/keys/';

    /** @var Filesystem */
    private $fileSystem;

    /** @var DateTimeService */
    private $timeService;

    public function __construct(
        AgentService $agentService,
        Filesystem $fileSystem,
        DateTimeService $timeService
    ) {
        parent::__construct($agentService);
        $this->fileSystem = $fileSystem;
        $this->timeService = $timeService;
    }

    /**
     * Checks if resuming a clone is possible
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agent" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr~")
     * })
     * @param string $agent
     * @param string $snapshot
     * @param string $extension
     * @param $guid string guid of image file
     * @return bool $result
     */
    public function canResume($agent, $snapshot, $extension, $guid): bool
    {
        $imageFilePath = $this->getImageFilePath($agent, $snapshot, $extension, $guid);

        return $this->fileSystem->exists($imageFilePath);
    }

    /**
     * Mark the transfer as still progressing.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_BMR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_BMR_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "snapshot" = @Symfony\Component\Validator\Constraints\Type(type = "numeric"),
     *   "extension" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~bmr~")
     * })
     * @param string $agentKey
     * @param int $snapshot
     * @param string $extension
     * @return bool
     */
    public function progressing(string $agentKey, int $snapshot, string $extension)
    {
        $this->updateZfsCloneTimestamp($agentKey, $snapshot, $extension);
        return true;
    }

    /**
     * @param string $agent
     * @param string $snapshot
     * @param string $extension
     * @param string $guid
     * @return string
     */
    private function getImageFilePath($agent, $snapshot, $extension, $guid): string
    {
        return self::POOL_PATH . $agent . '-' . $snapshot . '-' . $extension . '/' . $guid . self::SUFFIX_DATTO;
    }

    /**
     * Updates the zfs clone timestamp file
     *
     * @param string $agent
     * @param string $snapshot
     * @param string $extension
     */
    private function updateZfsCloneTimestamp($agent, $snapshot, $extension): void
    {
        $time = $this->timeService->getTime();
        $timestampFile = $this->getCloneTimestampFile($agent, $snapshot, $extension);
        $this->fileSystem->filePutContents($timestampFile, strval($time));
    }

    /**
     * @param string $agent
     * @param string $snapshot
     * @param string $extension
     * @return string
     */
    private function getCloneTimestampFile($agent, $snapshot, $extension): string
    {
        return self::KEY_PATH_BASE . $agent . '-' . $snapshot . '-' . $extension . '.' . static::TIMEOUT_FILE;
    }
}
