<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\System\Api\DeviceApiClientService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\Log\DeviceLoggerInterface;

/**
 * Establish connection to the source device
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ConnectStage extends AbstractMigrationStage
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var DeviceApiClientService */
    private $deviceClient;

    /**
     * @param Context $context
     * @param DeviceLoggerInterface $logger
     * @param DeviceApiClientService $deviceClient
     */
    public function __construct(
        Context $context,
        DeviceLoggerInterface $logger,
        DeviceApiClientService $deviceClient
    ) {
        parent::__construct($context);

        $this->logger = $logger;
        $this->deviceClient = $deviceClient;
    }

    /**
     * @inheritdoc
     * Ensure that we have connection credentials
     */
    public function commit()
    {
        // By calling the getDeviceClient, we are ensuring that we have the connection
        // credentials. Otherwise, an exception is thrown.
        $this->deviceClient->getDeviceClient();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        $this->deviceClient->disconnect();
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        $this->cleanup();
    }
}
