<?php

namespace Datto\ZFS;

use Datto\Utility\Storage\Zpool;

/**
 * Creates a ZpoolStatus from zpool status parser output
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ZpoolStatusFactory
{
    private Zpool $zpool;

    public function __construct(Zpool $zpool)
    {
        $this->zpool = $zpool;
    }

    /**
     * Create a ZpoolStatus object from the zpool status output of the given pool name
     *
     * @param string $poolName
     * @return ZpoolStatus
     */
    public function create(string $poolName): ZpoolStatus
    {
        $parsedZpoolStatus = $this->zpool->getParsedStatus($poolName, false);
        return new ZpoolStatus(
            $parsedZpoolStatus['rawOutput'] ?? '',
            $parsedZpoolStatus['pool'] ?? '',
            $parsedZpoolStatus['state'] ?? '',
            $parsedZpoolStatus['status'] ?? '',
            $parsedZpoolStatus['scan'] ?? '',
            $parsedZpoolStatus['config'] ?? [],
            $parsedZpoolStatus['errors'] ?? []
        );
    }
}
