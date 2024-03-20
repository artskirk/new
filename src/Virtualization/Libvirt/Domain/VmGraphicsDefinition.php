<?php

namespace Datto\Virtualization\Libvirt\Domain;

/**
 * Represents Libvirt Domain XML <graphics> element.
 * Refer to documentation for details:
 * {@link http://libvirt.org/formatdomain.html#elementsGraphics}
 */
class VmGraphicsDefinition
{
    const TYPE_VNC = 'vnc';
    const TYPE_SPICE = 'spice';
    const TYPE_SDL = 'sdl';
    const TYPE_DESKTOP = 'desktop';

    protected $type;
    protected $port;
    protected $display;
    protected $multiUser = false;
    protected $listen;
    protected $password;

    /**
     * Gets graphics adapter type.
     *
     * @return string
     *  One of the TYPE_* string constants.
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Sets graphics adapter type.
     *
     * @param string $type
     *  One of the TYPE_* string constants.
     * @return self
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Gets the port on which graphics adapter listens on.
     *
     * Either a numeric port, 'autoport' or '-1' (aka legacy autoport)
     *
     * @return string
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Sets the port on which graphics adapter listens on.
     *
     * Either a numeric port, 'autoport' or '-1' (aka legacy autoport)
     *
     * @param string $port
     * @return self
     */
    public function setPort($port)
    {
        $this->port = $port;
        return $this;
    }

    /**
     * Whether multi-user is enabled.
     *
     * @return bool
     */
    public function isMultiUser()
    {
        return $this->multiUser;
    }

    /**
     * Sets whether multi-user in enabled.
     *
     * @param bool $multiUser
     * @return self
     */
    public function setIsMultiUser($multiUser)
    {
        $this->multiUser = (bool) $multiUser;
        return $this;
    }

    /**
     * Gets 'listen' info for the graphics adapter.
     *
     * @return array
     *  An associative array with listen info. See:
     *  {@link http://libvirt.org/formatdomain.html#elementsGraphics}
     *  <code>
     *      $listen = array(
     *          'type' => 'address',
     *          'address' => '127.0.0.1',
     *      );
     *  </code>
     */
    public function getListen()
    {
        return $this->listen;
    }

    /**
     * Sets 'listen' info for the graphics adapter.
     *
     * @param array $listen
     *  An associative array with listen info. See:
     *  {@link http://libvirt.org/formatdomain.html#elementsGraphics}
     *  <code>
     *      $listen = array(
     *          'type' => 'address',
     *          'address' => '127.0.0.1',
     *      );
     *  </code>
     * @return self
     */
    public function setListen(array $listen)
    {
        $this->listen = $listen;
        return $this;
    }

    public function __toString(): string
    {
        return $this->getDefaultXml();
    }

    /**
     * Gets the password for access to this graphics adapter
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Sets the password for access to this graphics adapter
     * @param string $password
     * @return self
     */
    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @return string
     */
    private function getDefaultXml()
    {
        $root = new \SimpleXmlElement('<root></root>');
        $graphics = $root->addChild('graphics');
        $graphics->addAttribute('type', $this->getType());

        if ($this->getPort()) {
            if ($this->getPort() === 'autoport') {
                $graphics->addAttribute('autoport', 'yes');
            } else {
                $graphics->addAttribute('port', $this->getPort());
            }
        }

        if ($this->getPassword()) {
            $graphics->addAttribute('passwd', $this->getPassword());
        }

        if ($this->isMultiUser()) {
            $graphics->addAttribute('multiUser', 'yes');
        }

        if ($this->getListen()) {
            $listen = $graphics->addChild('listen');
            $info = $this->getListen();
            foreach ($info as $key => $value) {
                $listen->addAttribute($key, $value);
            }
        }

        return $root->graphics->asXml();
    }
}
