<?php

namespace Datto\Ipmi;

use Datto\Common\Resource\ProcessFactory;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class IpmiTool
{
    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * @return LanResult
     */
    public function getLan()
    {
        $process = $this->processFactory->get(['ipmitool', 'lan', 'print', '1']);

        $process->mustRun();

        $lines = explode(PHP_EOL, $process->getOutput());

        $ipAddress = null;
        $macAddress = null;

        /*
         * IP Address              : 10.0.22.191
         * Subnet Mask             : 255.255.252.0
         * MAC Address             : 1c:1b:0d:66:51:d9
         */
        foreach ($lines as $line) {
            $pieces = explode(":", $line, 2);

            if (count($pieces) === 2) {
                $header = trim($pieces[0]);
                $value = trim($pieces[1]);

                switch ($header) {
                    case 'MAC Address':
                        $macAddress = $value;
                        break;

                    case 'IP Address':
                        $ipAddress = $value;
                        break;
                }
            }
        }

        return new LanResult($ipAddress, $macAddress);
    }

    public function bmcResetCold()
    {
        $process = $this->processFactory->get(['ipmitool', 'bmc', 'reset', 'cold']);

        $process->mustRun();
    }
}
