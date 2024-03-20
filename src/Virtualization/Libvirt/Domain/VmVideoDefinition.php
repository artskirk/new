<?php
namespace Datto\Virtualization\Libvirt\Domain;

use \ArrayObject;

/**
 * Represents Libvirt Domain XML <video> element used to describe virtual
 * video card specs.
 * Refer to documentation for details:
 * {@link http://libvirt.org/formatdomain.html#elementsVideo}
 *
 * Optional when defining domain - will be auto-created with hypervisor defaults
 * if not specified.
 */
class VmVideoDefinition
{
    const MODEL_VGA = 'vga';
    const MODEL_CIRRUS = 'cirrus';
    const MODEL_VMWARE_VGA = 'vmvga';
    const MODEL_XEN = 'xen';
    const MODEL_QXL = 'qxl';

    protected $model;
    protected $vramKib = 8192;
    protected $heads = 1;
    protected $acceleration = null;
    protected $address;
    protected $isPrimary = true;

    public function __construct()
    {
        $this->acceleration = new ArrayObject();
    }

    /**
     * Gets video card model.
     *
     * @return string
     *  It will be one of he MODEL_* string constants.
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Sets video card.
     *
     * @param string $type
     *  It must be one of the MODEL_* string constants.
     * @return self
     */
    public function setModel($model)
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Gets the VRAM amount allocated to the video card in KiB.
     *
     * Defaults to 8192
     *
     * @return int
     *  VRAM in KiB
     */
    public function getVramKib()
    {
        return $this->vramKib;
    }

    /**
     * Sets the VRAM amount allocated to the video card in KiB.
     *
     * Defaults to 8192
     *
     * @param int $vramKib
     *  VRAM in KiB
     * @return self
     */
    public function setVramKib($vramKib)
    {
        $this->vramKib = (int) $vramKib;
        return $this;
    }

    /**
     * Gets the number of heads of the video card.
     *
     * Defaults to 1
     *
     * @return int
     */
    public function getHeads()
    {
        return $this->heads;
    }

    /**
     * Sets the number of heads for the video card.
     *
     * Defaults to 1
     *
     * @param int $numHeads
     * @return self
     */
    public function setHeads($numHeads)
    {
        $this->heads = (int) $numHeads;
        return $this;
    }

    /**
     * Gets the acceleration settings for the video card.
     *
     * @return ArrayObject|null
     *  Associative array with acceleration settings:
     *  <code>
     *      $acceleration = array(
     *          'accel2d' => 'yes',
     *          'accel3d' => 'no',
     *      );
     *  </code>
     */
    public function getAcceleration()
    {
        return $this->acceleration;
    }

    /**
     * Sets the acceleration settings for the video card.
     *
     * @param ArrayObject $acceleration
     *  Associative array with acceleration settings:
     *  <code>
     *      $acceleration = array(
     *          'accel2d' => 'yes',
     *          'accel3d' => 'no',
     *      );
     *  </code>
     * @return self
     */
    public function setAcceleration(ArrayObject $acceleration)
    {
        $this->acceleration = $acceleration;
        return $this;
    }

    /**
     * Gets the PCI slot address for passthrough video card.
     *
     * @return string
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * Sets the PCI slot address for passthrough video card.
     *
     * @param string $address
     * @return self
     */
    public function setAddress($address)
    {
        $this->address = $address;
        return $this;
    }

    /**
     * Whether the video card is primary.
     *
     * If primary and secondary are present, the secondary must be QXL.
     *
     * @return bool
     */
    public function isPrimary()
    {
        return $this->isPrimary;
    }

    /**
     * Sets whether it's a primary interface.
     *
     * If primary and secondary are present, the secondary must be QXL.
     *
     * @param bool $primary
     * @return self
     */
    public function setIsPrimary($primary)
    {
        $this->isPrimary = (bool) $primary;
        return $this;
    }

    public function __toString()
    {
        $root = new \SimpleXmlElement('<root></root>');
        $video = $root->addChild('video');

        $primary = $this->isPrimary() ? 'yes' : 'no';
        $video->addAttribute('primary', $primary);

        $model = $video->addChild('model');

        $model->addAttribute('type', $this->getModel());
        $model->addAttribute('vram', $this->getVramKib());
        $model->addAttribute('heads', $this->getHeads());

// this was causing problems on 20.04. Todo revisit whether it is needed or can be removed. see 'acceleration' on https://libvirt.org/formatdomain.html
//        if ($this->getAcceleration()) {
//            $acceleration = $model->addChild('acceleration');
//            foreach ($this->getAcceleration() as $key => $value) {
//                $acceleration->addAttribute($key, $value);
//            }
//        }

        return $root->video->asXml();
    }
}
