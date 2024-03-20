<?php

namespace Datto\Feature;

/**
 * Represents role of the device
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class DeviceRole
{
    const PHYSICAL = 'physical'; // Siris edge node, on-premises, bare-metal device
    const VIRTUAL = 'virtual';   // Virtual edge node, on-premises, virtual machine
    const CLOUD = 'cloud';       // Datto cloud device
    const AZURE = 'azure';       // Device that lives in azure
}
