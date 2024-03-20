<?php
namespace Datto\Virtualization\Libvirt\Domain;

/**
 * Represents Libvirt XML <input> device element.
 *
 * It is an optional input element which can then be referenced by e.g. a usb mouse which would be
 * specified as <input type='mouse' bus='usb'/>
 *
 * {@link https://libvirt.org/formatdomain.html#elementsInput}
 */
class VmInputDefinition
{
    const TYPE_MOUSE = 'mouse';
    const TYPE_TABLET = 'tablet';
    const TYPE_KEYBOARD = 'keyboard';
    const TYPE_PASSTHROUGH = 'passthrough';

    const BUS_XEN = 'xen';
    const BUS_USB = 'usb';
    const BUS_PS2 = 'ps2';
    const BUS_VIRTIO = 'virtio';

    protected $type;
    protected $bus;

    /**
     * VmInputDefinition constructor.
     *
     * @param string $type
     *  One of the TYPE_* string constants.
     * @param string $bus
     *  One of the BUS_* string constants.
     */
    public function __construct($type, $bus)
    {
        $this->type = $type;
        $this->bus = $bus;
    }

    /**
     * Gets the input type.
     *
     * A mandatory property that returns one of the TYPE_* string constants.
     *
     * @return string
     *  One of the TYPE_* string constants.
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Gets the controller bus.
     *
     * An optional property specifying the input bus type (ie. ps2, usb, virtio)
     *
     * @return string
     *  One of the BUS_* string constants.
     */
    public function getBus()
    {
        return $this->bus;
    }

    /**
     * Outputs this object as XML definition.
     *
     * @return string
     *  Libvirt XML representation of this object.
     */
    public function __toString()
    {
        // create fake root to stop asXml from outputting xml declaration.
        $root = new \SimpleXmlElement('<root></root>');

        $input = $root->addChild('input');
        $input->addAttribute('type', $this->getType());

        if ($this->getBus()) {
            $input->addAttribute('bus', $this->getBus());
        }

        return $root->input->asXml();
    }
}
