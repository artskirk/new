<?php

namespace Datto\Log;

/**
 * Common location for log formatter helper methods
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
trait LoggerHelperTrait
{
    /**
     * Removes metadata from the context that should not be displayed or propagated, e.g. asset.
     *
     * @param array $context
     * @return array Context with the hidden metadata fields removed
     */
    private function getContextWithHiddenMetadataRemoved(array $context): array
    {
        unset($context[DeviceLogger::CONTEXT_ASSET]);
        unset($context[DeviceLogger::CONTEXT_SESSION_ID]);
        unset($context[DeviceLogger::CONTEXT_NO_SHIP]);
        return $context;
    }
}
