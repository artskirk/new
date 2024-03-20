<?php

namespace Datto\Websockify;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * This class is responsible for managing websockify targets on the device.
 *
 * Websockify targets are cool because they allow us to
 * host multiple independent websockets all over the same port.
 *
 * The client picks which resource/target it wants to connect to by
 * specifying a token (identifier) in the initial handshake request.
 *
 * So basically, this allows us to turn this...
 *      var socketOne = new WebSocket("ws://mydevice.datto.lan:1001")
 *      var socketTwo = new WebSocket("ws://mydevice.datto.lan:1002")
 * Into this (note how the ports are the same):
 *      var socketOne = new WebSocket("ws://mydevice.datto.lan:1000/?token=one")
 *      var socketTwo = new WebSocket("ws://mydevice.datto.lan:1000/?token=two")
 *
 * And, using Apache's wstunnel module, we can even share ports 80/443 with the webUI:
 *      var socketOne = new WebSocket("ws://mydevice.datto.lan/websockify?token=one")
 *      var socketTwo = new WebSocket("wss://mydevice.datto.lan/websockify?token=one")
 *
 * This means we no longer have to create new RLY/DAS tunnels to access
 * noVNC sessions over remote web. It can all happen over the existing tunnel.
 *
 * @author Michael Meyer <mmeyer@datto.com>
 */
class WebsockifyService
{
    /*
     * This is where websockify is configured to get its targets from.
     * See 'datto-websockify.service' for details.
     */
    const WEBSOCKIFY_TARGETS_DIR = '/run/websockify-targets';

    const WEBSOCKIFY_TOKEN_FORMAT = 'vnc-%s';

    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Creates a new websockify target for the given target host+port.
     *
     * @param string $token
     *   The token ('identifier') to use for this target.
     * @param string $targetHost
     *   IP address or hostname of the target (e.g. Hypervisor IP)
     * @param int $targetPort
     *   Port number on the target (e.g. 5900 for VNC)
     */
    public function createTarget(string $token, string $targetHost, int $targetPort)
    {
        if (!$this->filesystem->isDir(self::WEBSOCKIFY_TARGETS_DIR)) {
            throw new WebsockifyException('Websockify targets directory does not exist. Is datto-websockify.service running?');
        }

        $contents = sprintf('%s: %s:%d', $token, $targetHost, $targetPort);
        $this->filesystem->filePutContents($this->getTargetFilePath($token), $contents);
    }

    /**
     * Removes the websockify target with the given token.
     *
     * @param string $token
     */
    public function removeTarget(string $token)
    {
        $targetPath = $this->getTargetFilePath($token);
        if ($this->filesystem->exists($targetPath)) {
            $this->filesystem->unlink($targetPath);
        }
    }

    /**
     * Format token string used to identify websocket for particular agent VNC connection
     *
     * @param string $agentKey
     * @return string
     */
    public function formatAgentToken(string $agentKey)
    {
        return sprintf(self::WEBSOCKIFY_TOKEN_FORMAT, $agentKey);
    }

    /**
     * @param string $token
     * @return string
     */
    private function getTargetFilePath(string $token): string
    {
        return self::WEBSOCKIFY_TARGETS_DIR . '/' . $token;
    }
}
