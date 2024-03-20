<?php

namespace Datto\Asset\Agent\Rescue\Stages;

use Datto\Asset\Agent\Rescue\RescueAgentCreationContext;
use Datto\Common\Resource\ProcessFactory;
use Datto\Cloud\AgentVolumeService;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Rescue agent creation stage to send non-critical agent info to the cloud.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CloudUpdateStage extends CreationStage
{
    const STATUS_MESSAGE = 'cloudUpdate';

    private AgentVolumeService $volumeService;
    private ProcessFactory $processFactory;

    /**
     * @param RescueAgentCreationContext $context
     * @param DeviceLoggerInterface $logger
     * @param AgentVolumeService $volumeService
     * @param ProcessFactory $processFactory
     */
    public function __construct(
        RescueAgentCreationContext $context,
        DeviceLoggerInterface $logger,
        AgentVolumeService $volumeService,
        ProcessFactory $processFactory
    ) {
        parent::__construct($logger, $context);

        $this->volumeService = $volumeService;
        $this->processFactory = $processFactory;
    }

    /**
     * Send encryption data to device web and and volume updates to partner portal.
     *
     * Note that this is the last stage in the process and failure here is non-critical.
     * Therefore we log exceptions rather than allowing them to trigger a rollback.
     */
    public function commit(): void
    {
        try {
            $this->volumeService->update($this->context->getRescueAgent()->getKeyName());

            $keyName = $this->context->getRescueAgent()->getKeyName();

            // This takes a long time and does not need to happen before the user starts using the rescue agent so
            // we run it in the background and return before it finishes.
            if ($this->context->getRescueAgent()->getEncryption()->isEnabled()) {
                $process = $this->processFactory->get(['snapctl', 'agent:encryption:keys:upload', '--background', $keyName]);
            } else {
                $process = $this->processFactory->get(['snapctl', 'asset:update:cloud', '--background', '--cron', '--force']);
            }

            $process->run();
        } catch (Exception $e) {
            $this->logger->error('RSC4001 Error sending encryption and volume updates to cloud', ['exception' => $e]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStatusMessage(): string
    {
        return self::STATUS_MESSAGE;
    }
}
