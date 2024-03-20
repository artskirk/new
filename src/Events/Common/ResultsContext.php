<?php

namespace Datto\Events\Common;

use Datto\Events\AbstractEventNode;
use InvalidArgumentException;

/**
 * Context node for a collection of results
 *
 * Since results contain error messages, which may vary significantly, they should only be used within the context node.
 */
class ResultsContext extends AbstractEventNode
{
    /** @var Result[] */
    protected $stageResults;

    /**
     * @param Result[] $stageResults
     */
    public function __construct(array $stageResults)
    {
        foreach ($stageResults as $result) {
            if (!$result instanceof Result) {
                throw new InvalidArgumentException('array values must be instances of ' . Result::class);
            }
        }
        $this->stageResults = $stageResults;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->stageResults;
    }
}
