<?php

namespace Datto\Asset\Agent\Job;

/**
 * List of Jobs for a specific Job type.
 *
 * @author John Roland <jroland@datto.com>
 */
class JobList
{
    /** @var string */
    private $type;

    /** @var BackupJobStatus[] */
    private $jobs;

    /**
     * JobList constructor.
     * @param string $type
     * @param BackupJobStatus[] $jobs
     */
    public function __construct($type, $jobs)
    {
        $this->type = $type;
        $this->jobs = $jobs;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return BackupJobStatus[]
     */
    public function getJobs()
    {
        return $this->jobs;
    }

    /**
     * @param BackupJobStatus[] $jobs
     */
    public function setJobs(array $jobs): void
    {
        $this->jobs = $jobs;
    }
}
