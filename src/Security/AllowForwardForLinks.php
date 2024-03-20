<?php

namespace Datto\Security;

use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Firewall\FirewallCmd;
use Psr\Log\LoggerAwareInterface;

/**
 * Class to help add firewall rules involving forwarding. This code would be a part of FirewallService, but LinkService
 * needs to call this, too, so this code was moved to its own class to prevent a circular reference.
 */
class AllowForwardForLinks implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private FirewallCmd $firewallCmd;

    public function __construct(
        FirewallCmd $firewallCmd
    ) {
        $this->firewallCmd = $firewallCmd;
    }

    /**
     * Adds firewall rules that allows packets to be forwarded in and out of the same bridge interface.
     * @param string[] $bridgeNames The bridge names to add the forward rules for.
     */
    public function allowForwardForLinks(array $bridgeNames): void
    {
        foreach ($bridgeNames as $bridgeName) {
            $this->firewallCmd->forwardBetweenSameInterface($bridgeName);
            $this->logger->debug('AFI0001 Adding rule to allow forwarding to and from interface', [
                'interface' => $bridgeName
            ]);
        }
    }
}
