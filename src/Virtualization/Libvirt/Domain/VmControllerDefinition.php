<?php
namespace Datto\Virtualization\Libvirt\Domain;

/**
 * Represents Libvirt XML <contoller> device element.
 *
 * It is an optional element that allows to specify contoller details such as
 * model and other properties which can then be referenced by e.g. a <disk>
 * device to more precisely describe its properties.
 *
 * {@link http://libvirt.org/formatdomain.html#elementsControllers}
 */
class VmControllerDefinition
{
    const TYPE_IDE = 'ide';
    const TYPE_SCSI = 'scsi';
    const TYPE_SATA = 'sata';
    const TYPE_FDC = 'fdc';
    const TYPE_USB = 'usb';
    const TYPE_CCID = 'ccid';
    const TYPE_VIRTIO_SERIAL = 'virtio-serial';
    const TYPE_PCI = 'pci';

    const MODEL_SCSI_AUTO = 'auto';
    const MODEL_SCSI_BUSLOGIC = 'buslogic';
    const MODEL_SCSI_IBMVSCSI = 'ibmvscsi';
    const MODEL_SCSI_LSILOGIC = 'lsilogic';
    const MODEL_SCSI_LSISAS1068 = 'lsisas1068';
    const MODEL_SCSI_LSISAS1078 = 'lsisas1078';
    const MODEL_SCSI_VIRTIO_SCSI = 'virtio-scsi';
    const MODEL_SCSI_VMPVSCSI = 'vmpvscsi';

    const MODEL_USB_PIIX3_UHCI = 'piix3-uhci';
    const MODEL_USB_PIIX4_UHCI = 'piix4-uhci';
    const MODEL_USB_EHCI = 'ehci';
    const MODEL_USB_ICH9_EHCI1 = 'ich9-ehci1';
    const MODEL_USB_ICH9_UHCI1 = 'ich9-uhci1';
    const MODEL_USB_ICH9_UHCI2 = 'ich9-uhci2';
    const MODEL_USB_ICH9_UHCI3 = 'ich9-uhci3';
    const MODEL_USB_VT82C686B_UHCI = 'vt82c686b-uhci';
    const MODEL_USB_PCI_OHCI = 'pci-ohci';
    const MODEL_USB_NEC_XHCI = 'nec-xhci';
    const MODEL_USB_NONE = 'none';

    protected $type;
    protected $index;
    protected $model;
    protected $ports;
    protected $vectors;
    protected $address;

    /**
     * Gets the controller type.
     *
     * A mandatory property that returns one of the TYPE_* string constants.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Sets the controller type.
     *
     * A mandatory property that specifies controller type.
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
     * Gets the controller index.
     *
     * A mandatory property describing in which order it is encountered.
     * Referenced in <address> element of the device XML.
     *
     * @return int
     */
    public function getIndex()
    {
        return $this->index;
    }

    /**
     * Sets the controller index.
     *
     * A mandatory property describing in which order it is encountered.
     * Referenced in <address> element of the device XML.
     *
     * @param int $index
     * @return self
     */
    public function setIndex($index)
    {
        $this->index = (int) $index;
        return $this;
    }

    /**
     * Gets the controller model.
     *
     * An optional property specifying hypervisor-specific controlled model.
     * Used only by TYPE_SCSI and TYPE_USB
     *
     * @return string
     *  One of the MODEL_* string constants.
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Sets the controller model.
     *
     * An optional property specifying hypervisor-specific controlled model.
     * Used only by TYPE_SCSI and TYPE_USB
     *
     * @param string $model
     *  One of the MODEL_* string constants.
     */
    public function setModel($model)
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Gets the ports property of the controller.
     *
     * Only used by TYPE_VIRTIO_SERIAL.
     *
     * @return int
     */
    public function getPorts()
    {
        return $this->ports;
    }

    /**
     * Sets the ports property of the controller.
     *
     * Only used by TYPE_VIRTIO_SERIAL.
     *
     * @param int $ports
     * @return self
     */
    public function setPorts($ports)
    {
        $this->ports = (int) $ports;
        return $this;
    }

    /**
     * Gets the vectors property of the controller.
     *
     * Only used by TYPE_VIRTIO_SERIAL.
     *
     * @return int
     */
    public function getVectors()
    {
        return $this->vectors;
    }

    /**
     * Sets the vectors property of the controller.
     *
     * Only used by TYPE_VIRTIO_SERIAL.
     *
     * @param int $vectors
     * @return self
     */
    public function setVectors($vectors)
    {
        $this->vectors = (int) $vectors;
        return $this;
    }

    /**
     * Gets the <address> element spec of the controller.
     *
     * Optional controller element for TYPE_PCI or TYPE_USB that specifies the
     * exact relationship of the controller to its master bus.
     *
     * @return array
     *  <code>
     *      $address = array(
     *          'type' => 'pci',
     *          'domain' => 0,
     *          'bus' => 0,
     *          'slot' => 4,
     *          'function' = 0,
     *          'multifunction' => 'on',
     *      );
     *  </code>
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * Sets the <address> element spec of the controller.
     *
     * Optional controller element for TYPE_PCI or TYPE_USB that specifies the
     * exact relationship of the controller to its master bus.
     *
     * @param array $address
     *  <code>
     *      $address = array(
     *          'type' => 'pci',
     *          'domain' => 0,
     *          'bus' => 0,
     *          'slot' => 4,
     *          'function' = 0,
     *          'multifunction' => 'on',
     *      );
     *  </code>
     */
    public function setAddress(array $address)
    {
        $this->address = $address;
        return $this;
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

        $controller = $root->addChild('controller');
        $controller->addAttribute('type', $this->getType());
        $controller->addAttribute('index', $this->getIndex());

        if ($this->getModel()) {
            $controller->addAttribute('model', $this->getModel());
        }

        if ($this->getPorts()) {
            $controller->addAttribute('ports', $this->getPorts());
        }

        if ($this->getVectors()) {
            $controller->addAttribute('vectors', $this->getVectors());
        }

        return $root->controller->asXml();
    }
}
