<?php

namespace Datto\ZFS;

use Exception;

/**
 * Represents an exception that is thrown from the ZfsDatasetService
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ZfsDatasetException extends Exception
{
    /**
     * @var ZfsDataset
     */
    private $dataset;

    /**
     * @param string $message
     * @param ZfsDataset $dataset
     */
    public function __construct($message, ZfsDataset $dataset)
    {
        parent::__construct($message);
        $this->dataset = $dataset;
    }

    /**
     * @return ZfsDataset
     */
    public function getDataset()
    {
        return $this->dataset;
    }
}
