<?php

namespace Datto\Network;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Resource\Sleep;
use Exception;

/**
 * The Fail2Ban support routines including snapctl support
 *
 * @author John Fury Christ <jchrist@datto.com>
 *
 */
class Fail2BanService
{
    const FAIL2BAN_BANTIME_5MIN = 300;
    const FAIL2BAN_BANTIME_1SEC = 1;
    const TIME_3SEC = 3;

    /** @var ProcessFactory */
    private $processFactory;

    /** @var Sleep */
    private $sleep;

    /**
     * @param ProcessFactory|null $processFactory
     * @param Sleep|null $sleep
     */
    public function __construct(
        ProcessFactory $processFactory = null,
        Sleep $sleep = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->sleep = $sleep ?: new Sleep();
    }

    /**
     * Use fail2ban to re-establish ssh functionality for a previously banned ip or all ips
     *
     * @param string $ip If $all is false $ip is must contain the ip to be unbanned.
     */
    public function unban(string $ip = '')
    {
        if (!empty($ip)) {
            $process = $this->processFactory->get(['fail2ban-client', 'unban', "$ip"]);
            $process->mustRun();
        } else {
            throw new Exception('Cannot unban blank ip');
        }
    }

    /**
     * Unban all jailed IPs
     */
    public function unbanAll()
    {
        $process = $this->processFactory->get(['fail2ban-client', 'unban', '--all']);
        $process->mustRun();
    }

    /**
     * Set the time that banned IPs will be blocked
     * @param int seconds the banned ip will be blocked
     */
    private function setBanTime($seconds)
    {
        $process = $this->processFactory->get(['fail2ban-client', 'set', 'sshd', 'bantime', $seconds]);
        $process->mustRun();
    }
}
