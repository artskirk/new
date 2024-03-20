<?php

namespace Datto\Iscsi;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Common\Resource\Sleep;
use Datto\Log\DeviceLoggerInterface;

/**
 * Responsible for creating RemoteInitiator objects
 * @author Matt Cheman <mcheman@datto.com>
 */
class RemoteInitiatorFactory
{
    /** @var Sleep */
    private $sleep;

    /** @var AgentApiFactory */
    private $agentApiFactory;

    /** @var AgentService */
    private $agentService;

    public function __construct(
        Sleep $sleep,
        AgentApiFactory $agentApiFactory,
        AgentService $agentService
    ) {
        $this->sleep = $sleep;
        $this->agentApiFactory = $agentApiFactory;
        $this->agentService = $agentService;
    }

    public function create(string $keyName, DeviceLoggerInterface $logger): RemoteInitiator
    {
        return new RemoteInitiator($keyName, $logger, $this->sleep, $this->agentApiFactory, $this->agentService);
    }
}
