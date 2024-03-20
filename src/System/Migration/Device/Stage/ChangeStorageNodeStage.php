<?php
namespace Datto\System\Migration\Device\Stage;

use Datto\Cloud\JsonRpcClient;
use Datto\System\Api\DeviceApiClientService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;

/**
 * Change the destination device's offsite storage node to match that of the source device
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class ChangeStorageNodeStage extends AbstractMigrationStage
{
    /** @var JsonRpcClient */
    private $portalClient;

    /** @var DeviceApiClientService */
    private $deviceClientService;

    /**
     * @param Context $context
     * @param JsonRpcClient $portalClient
     * @param DeviceApiClientService $deviceClientService
     */
    public function __construct(
        Context $context,
        JsonRpcClient $portalClient,
        DeviceApiClientService $deviceClientService
    ) {
        parent::__construct($context);
        $this->portalClient = $portalClient;
        $this->deviceClientService = $deviceClientService;
    }

    /**
     * @inheritdoc
     * Switch the destination device's storage node to the same one as the source device
     */
    public function commit()
    {
        $sourceDeviceId = $this->deviceClientService->call('v1/device/settings/getDeviceId');
        $this->portalClient->notifyWithId(
            'v1/device/offsite/colocate',
            ['deviceToMatch' => $sourceDeviceId]
        );
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
    }
}
