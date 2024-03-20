<?php

namespace Datto\Utility\User;

use Datto\Common\Resource\ProcessFactory;

/**
 * Utility for the "passwd" command.
 *
 * @author Chris McGehee <cmcgehee@datto.com>
 */
class Passwd
{
    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * True if password login is disabled. The user may still be able to log in via other means (e.g. SSH keys).
     */
    public function isPasswordDisabled(string $username): bool
    {
        $process = $this->processFactory->get([
            'passwd',
            '-S',
            $username
        ])->mustRun();
        // If password login is disabled, the output to this command will start with "username L".
        return strpos($process->getOutput(), $username . ' L') === 0;
    }
}
