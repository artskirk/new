<?php

namespace Datto\Backup\Stages;

use Datto\Asset\AssetType;
use Datto\Backup\BackupContext;
use Datto\Backup\BackupException;
use Datto\System\Transaction\Stage;
use Exception;

/**
 * Generic backup process stage.
 * This is used as the base class for all backup stages.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
abstract class BackupStage implements Stage
{
    /** @var BackupContext Context used by all stages for verification. */
    protected $context;

    /**
     * @inheritDoc
     */
    public function setContext($context)
    {
        if ($context instanceof BackupContext) {
            $this->context = $context;
        } else {
            throw new Exception('Expected BackupContext, received ' . get_class($context));
        }
    }

    /**
     * @inheritdoc
     */
    abstract public function commit();

    /**
     * @inheritdoc
     */
    abstract public function cleanup();

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        $this->cleanup();
    }

    /**
     * Verifies that the asset in the context is an agent.
     *
     * @param string $exceptionMessage Message to include in the exception, if thrown.
     */
    protected function assertAssetIsAnAgent(string $exceptionMessage)
    {
        $asset = $this->context->getAsset();
        if (!$asset->isType(AssetType::AGENT)) {
            throw new BackupException($exceptionMessage);
        }
    }
}
