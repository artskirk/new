<?php

namespace Datto\Device;

use Datto\Common\Resource\ProcessFactory;

class SecretKey
{
    const SECRET_KEY_SCRIPT = '/datto/scripts/secretKey.sh';

    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    public function get(): string
    {
        $process = $this->processFactory->get([self::SECRET_KEY_SCRIPT]);

        $process->mustRun();

        return trim($process->getOutput());
    }
}
