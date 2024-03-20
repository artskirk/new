<?php

namespace Datto\System\Migration\ZpoolReplace\Stage;

use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\ZFS\ZpoolService;

/**
 * Stage that handles setting on and off auto expand.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class SetAutoExpandOnStage extends AbstractMigrationStage
{
    /** @var ZpoolService */
    private $zpoolService;

    /**
     * SetAutoExpandOnStage constructor.
     *
     * @param Context $context
     * @param ZpoolService|null $zpoolService
     */
    public function __construct(
        Context $context,
        ZpoolService $zpoolService = null
    ) {
        parent::__construct($context);
        $this->zpoolService = $zpoolService ?: new ZpoolService();
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $this->zpoolService->activateAutoExpand(ZpoolService::HOMEPOOL);
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        $this->zpoolService->deactivateAutoExpand(ZpoolService::HOMEPOOL);
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        $this->zpoolService->deactivateAutoExpand(ZpoolService::HOMEPOOL);
    }
}
