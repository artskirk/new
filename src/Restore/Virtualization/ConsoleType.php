<?php

namespace Datto\Restore\Virtualization;

class ConsoleType
{
    /* @var string VNC over WebSocket used for remote console access */
    public const VNC = "vnc";

    /* @var string VMWare WebMKS (Mouse, Keyboard, and Screen) used for remote console access */
    public const WMKS = "wmks";
}
