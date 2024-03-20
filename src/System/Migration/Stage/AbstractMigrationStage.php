<?php

namespace Datto\System\Migration\Stage;

use Datto\System\Migration\Context;
use Datto\System\Transaction\Stage;

/**
 * Common fields and behaviour across every migration stage
 *
 * @author Mario Rial <mrial@datto.com>
 */
abstract class AbstractMigrationStage implements Stage
{
    /** @var Context */
    protected $context;

    /**
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * @inheritDoc
     */
    public function setContext($context)
    {
        // not yet implemented
    }
}
