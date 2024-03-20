<?php

namespace Datto\App\Controller\Api\V1\Device\Storage;

use Datto\Log\LoggerAwareTrait;
use Datto\Service\Storage\PublicCloud\PoolExpansionStateManager;
use Psr\Log\LoggerAwareInterface;

/**
 * API endpoints for managing expansions of the local data pool.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class Expand implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private PoolExpansionStateManager $poolExpansionStateManager;

    public function __construct(PoolExpansionStateManager $poolExpansionStateManager)
    {
        $this->poolExpansionStateManager = $poolExpansionStateManager;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_PUBLIC_CLOUD_POOL_EXPANSION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_PUBLIC_CLOUD_POOL_EXPANSION")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "diskLun" = {
     *     @Symfony\Component\Validator\Constraints\Type(type = "int"),
     *     @Symfony\Component\Validator\Constraints\NotNull(),
     *     @Symfony\Component\Validator\Constraints\GreaterThanOrEqual(value = 0)
     *   }
     * })
     *
     * @param int $diskLun
     * @param bool $resize
     *
     * @return bool
     */
    public function start(int $diskLun, bool $resize = false): bool
    {
        return $this->poolExpansionStateManager->startPoolExpansionBackground($diskLun, $resize);
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_PUBLIC_CLOUD_POOL_EXPANSION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_PUBLIC_CLOUD_POOL_EXPANSION")
     *
     * @return string
     */
    public function getState(): string
    {
        $status = $this->poolExpansionStateManager->getPoolExpansionState();
        return $status->getState();
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_PUBLIC_CLOUD_POOL_EXPANSION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_PUBLIC_CLOUD_POOL_EXPANSION")
     *
     * @param string $status
     *
     * @return bool
     */
    public function setFailed(): bool
    {
        $this->logger->error('EXP0000 Storage pool expansion failed externally');
        $this->poolExpansionStateManager->setFailed(false);

        return true;
    }
}
