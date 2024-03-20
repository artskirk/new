<?php
namespace Datto\Screenshot;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Agentless;
use Datto\Asset\Agent\Linux\LinuxAgent;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Lakitu\Client\InspectionClient;
use Datto\Lakitu\Client\Transport\AbstractTransportClient;
use Datto\Log\LoggerAwareTrait;
use Datto\Virtualization\VirtualMachine;
use Psr\Log\LoggerAwareInterface;

/**
 * Create a Status object for a VM.
 */
class StatusFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function create(Agent $agent, VirtualMachine $vm)
    {
        $connection = $vm->getConnection();

        $supportedByLakitu = !$connection->isHyperV() && (
                $agent instanceof LinuxAgent
                || $agent instanceof Agentless\Linux\LinuxAgent
                || $agent instanceof WindowsAgent
                || $agent instanceof Agentless\Windows\WindowsAgent
            );

        if ($supportedByLakitu) {
            try {
                return $this->createLakituStatus($vm);
            } catch (\Exception $ex) {
                $this->logger->error('SFA0001 Failed to create LakituStatus. Defaulting to UnknownStatus.', ['exception' => $ex]);
            }
        }

        return $this->createUnknownStatus($vm);
    }

    /**
     * Create a LakituStatus object for a VM.
     *
     * A Unix domain socket is used to communicate with local VMs.
     * A TCP network socket is used to communicate with remote VMs.
     *
     * @param VirtualMachine $vm
     * @param AbstractTransportClient|null $transport
     *   (optional) The transport to use, mainly for injection turing tests.
     *
     * @return LakituStatus
     */
    private function createLakituStatus(VirtualMachine $vm, AbstractTransportClient $transport = null): LakituStatus
    {
        $this->logger->debug('SFA0004 Creating LakituStatus for ' . $vm->getName());

        $transport = $transport ?: $vm->createSerialTransport();

        return new LakituStatus(new InspectionClient($this->logger, $transport), $this->logger);
    }

    /**
     * Create an UnknownStatus object for an unsupported VM.
     */
    private function createUnknownStatus(VirtualMachine $vm): UnknownStatus
    {
        $this->logger->debug('SFA0005 Creating UnknownStatus for ' . $vm->getName());

        return new UnknownStatus($this->logger);
    }
}
