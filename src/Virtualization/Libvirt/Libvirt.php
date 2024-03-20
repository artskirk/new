<?php
namespace Datto\Virtualization\Libvirt;

use Datto\Connection\ConnectionType;
use Datto\Log\LoggerAwareTrait;
use Exception;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

/* if libvirt-php is not installed this constant will not be defined
 * and break unit tests */
if (!defined('VIR_KEYCODE_SET_XT')) {
    define('VIR_KEYCODE_SET_XT', 1);
}

/**
 * A wrapper class for libvirt-php functions.
 *
 * Not all libvirt-php functions are used here, e.g. host network config or
 * storage pool & storage vol management as not all hypervisors make use of them
 * or have them fully implemented (yet). So this class provides only methods
 * that are known to work well across the board for all the hypervisors we
 * to support
 *
 * For complete reference of libvirt-php functions see:
 * {@link http://libvirt.org/php/api-reference.html}
 *
 */
class Libvirt implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    const STATE_NOSTATE = 'No State'; // no state
    const STATE_RUNNING = 'Running'; // the domain is running
    const STATE_BLOCKED = 'Blocked'; // the domain is blocked on resource
    const STATE_PAUSED = 'Paused'; // the domain is paused by user
    const STATE_SHUTDOWN = 'Shutdown'; // the domain is being shut down
    const STATE_SHUTOFF = 'Shutoff'; // the domain is shut off
    const STATE_CRASHED = 'Crashed'; // the domain is crashed
    const STATE_UNKNOWN = 'Unknown';

    const STATES = [
        self::STATE_RUNNING,
        self::STATE_NOSTATE,
        self::STATE_BLOCKED,
        self::STATE_PAUSED,
        self::STATE_SHUTDOWN,
        self::STATE_SHUTOFF,
        self::STATE_CRASHED,
        self::STATE_UNKNOWN
    ];

    /**
     * Need to be able to store the libvirt connection on a per URI basis.
     *
     * @var array
     */
    private static $handle = array();

    private $lastError;

    /** @var string */
    private $uri;

    /** @var ConnectionType */
    private $connectionType;

    /** @var array */
    private $credentials;

    /**
     * Constructor connects to hypervisor via libvirt.
     *
     * @param ConnectionType $connectionType
     * @param string $uri
     * @param array|null $credentials
     */
    public function __construct(
        ConnectionType $connectionType,
        string $uri,
        $credentials
    ) {
        $this->connectionType = $connectionType;
        $this->uri = $uri;
        $this->credentials = $credentials;

        if (!isset(self::$handle[$this->uri])) {
            $this->connect();
        }
    }

    /**
     * Returns whether the libvirt connection was successful
     *
     * @return bool
     */
    public function isConnected()
    {
        return $this->getHandle() !== false;
    }

    /**
     * Gets the last error message captured from libvirt.
     *
     * @return string
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Sets up the logger.
     *
     * @return LoggerInterface
     */
    private function setupLogger()
    {
        $formatter = new LineFormatter(null, null, false, true);
        $handler = new StreamHandler('/var/log/datto/libvirt.log');
        $handler->setFormatter($formatter);
        $log = new Logger('libvirt');
        $log->pushHandler($handler);

        return $log;
    }

    /**
     * Changes VCPU count for specified domain.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @param int $num
     *  Number of VCPUs to set.
     * @return bool
     *  True/False depending if operation succeeded. Use getLastError() method
     *  to get the error message after failure.
     * @codeCoverageIgnore
     */
    public function domainChangeVCpus($domain, $num)
    {
        $dom = $this->getDomainObject($domain);

        $ret = libvirt_domain_change_vcpus($dom, $num);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Changes the domain memory allocation.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @param int $memory
     *  Number of MiBs to be set as immediate memory value.
     * @param int $maxmem
     *  Number of MiBs to be set as the maximum allocation.
     * @return resource|bool
     *  Either new domain resource on success, or boolean false on fail.
     *  Use getLastError() method to get the error message after failure.
     * @codeCoverageIgnore
     */
    public function domainChangeMemoryAllocation($domain, $memory, $maxmem)
    {
        $dom = $this->getDomainObject($domain);

        $ret = libvirt_domain_change_memory($dom, $memory, $maxmem);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Changes boot device order on the domain.
     *
     * @param resource|string $res
     *  Domain resource, name or UUID.
     * @param string $first
     *  The name of the first boot device.
     * @param string $second
     *  Optional, name of the second boot device.
     * @return resource|bool
     *  Either new domain resource on success, or boolean false on fail.
     *  Use getLastError() method to get the error message after failure.
     * @codeCoverageIgnore
     */
    public function domainChangeBootDevices($res, $first, $second)
    {
        $dom = $this->getDomainObject($res);

        $ret = libvirt_domain_change_boot_devices($dom, $first, $second);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets the screenshot of the running domain.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return mixed|bool
     *  PNG screenshot data or false on failure.
     *  Use getLastError() method to get the error message after failure.
     * @codeCoverageIgnore
     */
    public function domainGetScreenshot($domain)
    {
        $dom = $this->getDomainObject($domain);
        $ret = @libvirt_domain_get_screenshot_api($dom, 0);

        if ($ret && file_exists($ret['file'])) {
            if ($ret['mime'] != 'image/png') {
                $fileNew = $ret['file'] . '.png';

                exec(sprintf('convert %s %s', $ret['file'], $fileNew));
                unlink($ret['file']);

                $ret['file'] = $fileNew;
                $ret['mime'] = 'image/png';
            }

            $data = file_get_contents($ret['file']);
            unlink($ret['file']);

            return $data;
        } else {
            return $this->setLastError();
        }
    }

    /**
     * @param resource|string $domain
     * @param string $path file path to save jpeg screenshot
     * @return bool|string true if successful, otherwise string error
     */
    public function domainSaveScreenshotJpeg($domain, $path)
    {
        $pngContent = $this->domainGetScreenshot($domain);

        if (!$pngContent) {
            return $this->getLastError();
        }

        $image = imagecreatefromstring($pngContent);

        if (!is_resource($image) || get_resource_type($image) !== 'gd') {
            return 'Failed to create image resource.';
        }

        $jpegSuccess = imagejpeg($image, $path, 100);
        if (!$jpegSuccess) {
            $error = error_get_last();
            if (file_exists($path)) {
                // In case imagejpeg() started writing the file and then failed, delete it.
                @unlink($path);
            }
            return sprintf('Failed to write file (%s): %s', $path, $error['message']);
        }

        return true;
    }

    /**
     * Gets the screenshot thumbnail of the running domain.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @param int $width
     *  Optional, desired thumbnail width in pixels.
     * @return mixed|bool
     *  PNG screenshot data or false on failure.
     *  Use getLastError() method to get the error message after failure.
     */
    public function domainGetScreenshotThumbnail($domain, $width = 120)
    {
        $screen = $this->domainGetScreenshot($domain);
        $imgFile = tempnam("/tmp", "libvirt-php-tmp-resize-XXXXXX");

        if ($screen) {
            file_put_contents($imgFile, $screen);
        } else {
            unlink($imgFile);
            return false;
        }

        if (file_exists($imgFile)) {
            list($w, $h) = getimagesize($imgFile);
            $height = ($h / $w) * $width;
        } else {
            unlink($imgFile);
            return false;
        }

        $new = imagecreatetruecolor($width, $height);
        $img = imagecreatefrompng($imgFile);
        imagecopyresampled($new, $img, 0, 0, 0, 0, $width, $height, $w, $h);
        imagedestroy($img);

        imagepng($new, $imgFile);
        imagedestroy($new);

        $data = file_get_contents($imgFile);

        unlink($imgFile);
        return $data;
    }

    /**
     * Gets the screen dimensions of the running domain.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return array|bool
     *  Array with 'height' and 'width' keys pointing to size in pixels.
     *  False on failure, use getLastError() method to get actual error message.
     */
    public function domainGetScreenDimensions($domain)
    {
        $screen = $this->domainGetScreenshot($domain);
        $imgFile = tempnam("/tmp", "libvirt-php-tmp-resize-XXXXXX");

        $width = false;
        $height = false;

        if ($screen) {
            $fp = fopen($imgFile, "wb");
            fwrite($fp, $screen);
            fclose($fp);
        } else {
            return false;
        }

        if (file_exists($imgFile)) {
            list($width, $height) = getimagesize($imgFile);
        } else {
            return false;
        }

        unlink($imgFile);

        return array('height' => $height, 'width' => $width);
    }

    /**
     * Gets the domain name.
     *
     * @param resource $resource
     *  Domain resource.
     * @return string
     *  Domain name.
     * @codeCoverageIgnore
     */
    public function domainGetName($resource)
    {
        return libvirt_domain_get_name($resource);
    }

    /**
     * Gets the domain UUID
     *
     * @param string|resource $dom
     *  VM name, UUID or resource handle.
     *
     * @return string|bool
     *  UUID string or false on failure.
     */
    public function domainGetUuid($dom)
    {
        $dom = $this->getDomainObject($dom);

        if (!$dom) {
            return false;
        }

        $ret = $this->getXpath($dom, '//domain/uuid');

        if (count($ret) === 1) {
            return $ret[0];
        } else {
            return false;
        }
    }

    /**
     * Gets the domain ID (must be running)
     *
     * @param string|resource $dom
     *  VM name, UUID or resource handle.
     *
     * @return string
     *  VM ID or "-1" if not running.
     * @codeCoverageIgnore
     */
    public function domainGetId($dom)
    {
        $dom = $this->getDomainObject($dom);

        if (!$dom) {
            return false;
        }

        return libvirt_domain_get_id($dom);
    }

    /**
     * Get the paths to all disks that are currently attached to VM.
     *
     * @param string|resource $domain
     *  Domain resource, name or UUID.
     *
     * @return array|bool
     *  Array of disk paths or false on failure.
     */
    public function domainGetAttachedDiskPaths($domain)
    {
        $dom = $this->getDomainObject($domain);

        if (!$dom) {
            return false;
        }

        $ret = $this->getXpath($dom, '//disk/source/@file', true);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets the VNC password (if VNC is enabled).
     *
     * @param string|resource $domain
     *  Domain resource, name or UUID.
     *
     * @return string|bool
     *  The VNC password or false on failure.
     */
    public function domainGetVncPassword($domain)
    {
        $dom = $this->getDomainObject($domain);

        if (!$dom) {
            return false;
        }

        $ret = $this->getXpath($dom, '//graphics[@type="vnc"][1]/@passwd', true, true);

        return !empty($ret) ? $ret[0] : $this->setLastError();
    }

    /**
     * Gets the VNC port (if VNC is enabled).
     *
     * @param string|resource $domain
     *  Domain resource, name or UUID.
     *
     * @return int|bool
     *  The VNC port or false on failure.
     */
    public function domainGetVncPort($domain)
    {
        $dom = $this->getDomainObject($domain);

        if (!$dom) {
            return false;
        }

        $ret = $this->getXpath($dom, '//graphics[@type="vnc"][1]/@port', true);

        // Handle xpath query failures
        if (empty($ret)) {
            return $this->setLastError();
        }

        // If a port was not specified, it will return -1, which generally
        // means it's set to the default VNC port which is 5900.
        $isDefaultPort = $ret[0] === '-1';
        $port = $isDefaultPort ? 5900 : (int) $ret[0];

        return $port;
    }

    /**
     * Whether the domain is running.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return bool
     */
    public function domainIsRunning($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $tmp = $this->domainGetInfo($dom);
        if (!$tmp) {
            return $this->setLastError();
        }
        $ret = (($tmp['state'] == VIR_DOMAIN_RUNNING)
            || ($tmp['state'] == VIR_DOMAIN_BLOCKED));

        return $ret;
    }

    /**
     * Whether the domain is paused.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return bool
     */
    public function domainIsPaused($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $tmp = $this->domainGetInfo($dom);
        if (!$tmp) {
            return $this->setLastError();
        }
        $ret = (($tmp['state'] == VIR_DOMAIN_PAUSED)
            || ($tmp['state'] == VIR_DOMAIN_SHUTOFF));

        return $ret;
    }

    /**
     * Whether the domain is shut off.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return bool
     */
    public function domainIsShutOff($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $tmp = $this->domainGetInfo($dom);
        if (!$tmp) {
            return $this->setLastError();
        }
        $ret = ($tmp['state'] == VIR_DOMAIN_SHUTOFF);

        return $ret;
    }

    /**
     * Gets human readable domain state.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return string
     *  Human readable domain state.
     */
    public function domainGetState($domain)
    {
        $dom = $this->getDomainObject($domain);

        if (!$dom) {
            return 'Unknown';
        }

        $tmp = $this->domainGetInfo($domain);

        if (!$tmp) {
            return $this->setLastError();
        }

        $state = $tmp['state'];

        return $this->domainStateTranslate($state);
    }

    /**
     * Defines a domain from XML.
     *
     * @param string $xml
     *  Libvirt XML definition for domain.
     * @return resource|bool
     *  Domain resource or false on failure.
     *  Use the getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function domainDefine($xml)
    {
        $ret = libvirt_domain_define_xml(self::$handle[$this->uri], $xml);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Undefines a domain.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return bool
     *  When false, use getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function domainUndefine($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $ret = libvirt_domain_undefine($dom);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Starts the domain
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return bool
     *  When false, use getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function domainStart($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $ret = libvirt_domain_create($dom);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gracefully shutsdown the domain.
     *
     * It depends on guest OS support for ACPI shutdown signal or hypervisor
     * driver support, so it's not reliable.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return bool
     *  When false, use getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function domainShutdown($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $ret = libvirt_domain_shutdown($dom);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Destroys the domain - aka pull power cord.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return bool
     *  When false, use getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function domainDestroy($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $ret = libvirt_domain_destroy($dom);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Suspends the domain.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return bool
     *  When false, use getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function domainSuspend($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $ret = libvirt_domain_suspend($dom);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Resumes the domain.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return bool
     *  When false, use getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function domainResume($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $ret = libvirt_domain_resume($dom);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Restarts teh domain.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @return bool
     *  When false, use getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function domainReboot($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $ret = libvirt_domain_reboot($dom);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Sends key code sequence to a domain.
     *
     * @param resource|string $domain
     * @param array $keyCodes
     *  Codeset codes (not ASCII coded, google e.g. XT key codes)
     * @param int $codeSet
     *  (Optional) The codeset to use VIR_KEYCODE_SET_XT by default.
     *  It does not matter which in practice as most drivers translate code
     *  tables internally but libvirt API lets to set it so here we have it.
     * @param int $holdTime
     *  (Optional) How long (in miliseconds) to "hold" down the keys.
     *
     * @return bool
     * @codeCoverageIgnore
     */
    public function domainSendKeys(
        $domain,
        array $keyCodes,
        $codeSet = VIR_KEYCODE_SET_XT,
        $holdTime = 0
    ) {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $ret = libvirt_domain_send_key_api($dom, $codeSet, $holdTime, $keyCodes);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets the current libvirt connection resource. Returns false if not connected.
     *
     * @return resource|false
     */
    private function getHandle()
    {
        return self::$handle[$this->uri] ?? false;
    }

    /**
     * Gets the hostname of the guest associated with the connection.
     *
     * @return string
     * @codeCoverageIgnore
     */
    public function getHostname()
    {
        return libvirt_connect_get_hostname(self::$handle[$this->uri]);
    }

    /**
     * Gets the domain resource handle
     *
     * @param resource|string $nameRes
     *  Domain resource, name or UUID.
     * @return resource|bool
     *  The domain resource handle, false on failure.
     *  Use the getLastError() method to get the actual error message.
     * @codeCoverageIgnore
     */
    public function getDomainObject($nameRes)
    {
        if (is_resource($nameRes)) {
            return $nameRes;
        }

        $dom = @libvirt_domain_lookup_by_uuid_string(self::$handle[$this->uri], $nameRes);

        if (!$dom) {
            $dom = @libvirt_domain_lookup_by_name(self::$handle[$this->uri], $nameRes);
            if (!$dom) {
                return $this->setLastError();
            }
        }

        return $dom;
    }

    /**
     * Allows to query domain info using XPATH
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @param string $xpath
     *  The XPATH query, e.g. '//domain/devices/graphics[@type="vnc"]/@port'
     * @param bool $inactive
     *  Whether to return results even if domain is not active.
     * @param bool $security
     *  Whether to return security sensitive information
     * @return array|bool
     *  An array with values in 0-indexed keys.
     *  <code>
     *      $result = array(
     *          0 => 'mouse',
     *          1 => 'keyboard',
     *      );
     *  </code>
     * @codeCoverageIgnore
     */
    public function getXpath($domain, $xpath, $inactive = false, $security = false)
    {
        $dom = $this->getDomainObject($domain);
        $flags = 0;
        if ($inactive) {
            $flags = VIR_DOMAIN_XML_INACTIVE;
        }
        if ($security) {
            $flags |= VIR_DOMAIN_XML_SECURE;
        }

        $ret = libvirt_domain_xml_xpath($dom, $xpath, $flags);
        if (!$ret) {
            return $this->setLastError();
        }

        // unset stupid extra elements libvirt-php returns
        unset($ret['num']);
        unset($ret['xpath']);

        return $ret;
    }

    /**
     * Gets a free memory on the hypervisor in bytes.
     *
     * Since PHP cannot handle big integers the memory amount is returned as
     * string. Therefore it's recommended to use bcmath to make calculations.
     *
     * @return string
     *  Free memory amount in bytes as string or false on failure.
     *  Use the getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function hostGetNodeFreeMemory()
    {
        $ret = libvirt_node_get_free_memory(self::$handle[$this->uri]);

        return ($ret) ? $ret : $this->setLastError();
    }

    /**
     * Gets total memory on the hypervisor in bytes.
     *
     * Since PHP cannot handle big integers the memory amount is returned as
     * string. Therefore it's recommended to use bcmath to make calculations.
     *
     * @return string|bool
     *  Total memory amount in bytes as string or false on failure.
     *  Use the getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function hostGetNodeTotalMemory()
    {
        $ret = $this->hostGetNodeInfo();

        if (is_array($ret)) {
            return $ret['memory'];
        }

        return $this->setLastError();
    }

    /**
     * Gets the CPU count on the hypervisor.
     *
     * @return int 0 is returned if this info could not be read.
     *             Use the getLastError() method to get actual error message.
     */
    public function hostGetNodeCpuCount(): int
    {
        $ret = $this->hostGetNodeInfo();

        if (is_array($ret)) {
            return $ret['cpus'];
        }

        return 0;
    }

    /**
     * Returns the CPU model of the virtualization host.
     *
     * @return string|null CPU model of the virtualization host
     * or null if CPU model could not be found.
     */
    public function hostGetCpuModel()
    {
        $xmlStr = libvirt_connect_get_capabilities(self::$handle[$this->uri]);
        if (!$xmlStr) {
            return null;
        }
        $simpleXmlElement = simplexml_load_string($xmlStr);
        $arr = $simpleXmlElement->xpath("/capabilities/host/cpu/model");

        return $arr ? $arr[0] : null;
    }

    /**
     * Returns basic hypervisor info such as number of CPUs, memory etc.
     *
     * @return array|bool
     *  <code>
     *      $info = array(
     *          'model' => 'Intel Xeon CPU E5-2620 0 @2.00',
     *          'memory' => 134189376, // in KiB
     *          'cpus' => 12,
     *          'nodes' => 2,
     *          'sockets' => 2,
     *          'cores' => 6,
     *          'threads' => 2,
     *          'mhz' => 1999,
     *      );
     *  </code>
     * @codeCoverageIgnore
     */
    public function hostGetNodeInfo()
    {
        $ret = libvirt_node_get_info(self::$handle[$this->uri]);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets the domain count on defined in hypervisor.
     *
     * @return int|bool
     *  Domain count or false on failure.
     *  Use the getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function hostGetDomainCount()
    {
        $tmp = libvirt_domain_get_counts(self::$handle[$this->uri]);
        return ($tmp) ? $tmp : $this->setLastError();
    }

    /**
     * Returns an array of storage pool names on the connection.
     *
     * @return string[]
     * @codeCoverageIgnore
     */
    public function hostGetStoragePools()
    {
        $ret = libvirt_list_storagepools(self::$handle[$this->uri]);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Returns a free VNC port on the hypervisor.
     *
     * @todo: This is kind of ESX specific so we'll need to check if other
     *        hypervisors also need this. The logic should be the same though.
     * @return int
     *  A VNC port that is not already in use by other VMs on the host.
     * @codeCoverageIgnore
     */
    public function hostGetFreeVncPort(): int
    {
        $doms = libvirt_list_domain_resources(self::$handle[$this->uri]);

        $vncPortsAlreadyAssigned = [];

        foreach ($doms as $dom) {
            $port = $this->domainGetVncPort($dom);
            if ($port !== false) {
                $vncPortsAlreadyAssigned[] = $port;
            }
        }

        for ($i = 5901; $i < 5965; $i++) {
            if (!in_array($i, $vncPortsAlreadyAssigned)) {
                return $i;
            }
        }

        $this->logger->error('VNC0000 Failed to find a free VNC port.');
        throw new Exception('Failed to find a free VNC port on the hypervisor.');
    }

    /**
     * Gets storage pool information as array.
     *
     * @param resource|string $pool
     *  Storage resource or name.
     * @return array|bool
     *  Returns storage pool info as array:
     *  <code>
     *      $array = array(
     *          'state' => 'running',
     *          'capacity' => 1234, // in KiB
     *          'allocation' => 1234, // in KiB
     *          'available' => 1234, // in KiB
     *      );
     *  </code>
     * @codeCoverageIgnore
     */
    public function storagePoolGetInfo($pool)
    {
        if (!($res = $this->getStoragePoolResource($pool))) {
            return false;
        }

        $ret = libvirt_storagepool_get_info($res);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets the XML string of the storage pool definition.
     *
     * @param resource|string $pool
     *  Storage pool resource handle or name.
     * @param string $xpath
     *  Optional, xPath expression to get specific value.
     * @return string|bool
     *  Storage pool XML string or result of xPath expression. False on failure.
     *  Use the getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function storagePoolGetXml($pool, $xpath = null)
    {
        if (!($res = $this->getStoragePoolResource($pool))) {
            return false;
        }

        $ret = libvirt_storagepool_get_xml_desc($res, $xpath);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Returns a list of domain names defined on the hypervisor.
     *
     * @return string[]
     *  An array of domain names.
     * @codeCoverageIgnore
     */
    public function hostGetDomains()
    {
        $ret = libvirt_list_domains(self::$handle[$this->uri]);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets a list of networks defined in the hypervisor.
     * @deprecated This function will not work for hyper-v since we did not implement virConnectListNetworks() in our
     *     hyper-v patches for libvirt. Use hostGetAllNetworks() instead.
     *
     * @return string[]|bool
     *  An array of network names or false on failure.
     *  Use the getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function hostGetNetworkList()
    {
        $ret = libvirt_list_networks(self::$handle[$this->uri]);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Get a list of networks defined in the hypervisor.
     *
     * Differs from hostGetNetworkList in that it returns an array of network
     * resource handles, rather than just array of names (there can be duplicate
     * network names on some hypervisors).
     *
     * @return resource[]|bool
     */
    public function hostGetAllNetworks()
    {
        $ret = libvirt_list_all_networks(self::$handle[$this->uri]);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Get the network name using the network resource handle.
     *
     * @param resource $net
     *
     * @return string|bool
     * @codeCoverageIgnore
     */
    public function networkGetName($net)
    {
        $ret = libvirt_network_get_name($net);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Get the nework UUID string using the network resource handle.
     *
     * @param resource $net
     *
     * @return string|bool
     * @codeCoverageIgnore
     */
    public function networkGetUUIDString($net)
    {
        $ret = libvirt_network_get_uuid_string($net);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Get the network UUID (binary) using the network resource handle.
     *
     * Not quite sure exactly what "binary" means, but seem to be an array
     * of bytes that one can format the UUID as desired using (un)pack, sprintf
     * or similar.
     *
     * @param string $net
     *
     * @return array|bool
     * @codeCoverageIgnore
     */
    public function networkGetUUID($net)
    {
        $ret = libvirt_network_get_uuid($net);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets network XML string or result of xPath expression.
     *
     * @param resource|string $net
     *  Either a network resource handle or network name.
     * @param string $xpath
     *  Optional xPath expression to get just this entry.
     * @return string|bool
     *  Network XML string or xPath expression result or false on failure.
     *  Use the getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function networkGetXml($net, $xpath = null)
    {
        if (!($res = $this->getNetworkResource($net))) {
            return false;
        }

        $ret = libvirt_network_get_xml_desc($res, $xpath);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets XML string of the domain definition.
     *
     * @param resource|string $domain
     *  Domain resource, name or UUID.
     * @param bool $getInactive
     *  Even if it's inactive.
     * @return string
     *  Domain XML string.
     * @codeCoverageIgnore
     */
    public function domainGetXml($domain, $getInactive = false)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        $ret = libvirt_domain_get_xml_desc($dom, $getInactive ? VIR_DOMAIN_XML_INACTIVE : 0);
        return $ret ? $ret : $this->setLastError();
    }


    /**
     * Gets a native VM configuration file.
     *
     * By default libvirt operates on a unified XML format for all supported
     * hypervisors. This method allows to return VM config in the hypervisor's
     * native format.
     *
     * @param string|resource $nameRes
     *  Domain resource, name or UUID.
     * @return string|bool FALSE on error
     * @codeCoverageIgnore
     */
    public function domainXmlToNative($nameRes)
    {
        $dom = $this->getDomainObject($nameRes);
        if (!$dom) {
            return false;
        }

        $xml = $this->domainGetXml($dom);
        if (!$xml) {
            return false;
        }

        $type = $this->connectionType->value();

        //libvirt refers to ESX as VMWare internally.
        if ($type == 'esx') {
            $type = 'vmware-vmx';
        }
        $ret = libvirt_domain_xml_to_native(self::$handle[$this->uri], $type, $xml);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Establishes hypervisor connection via libvirt
     *
     * @codeCoverageIgnore
     */
    private function connect()
    {
        if (!empty($this->credentials)) {
            $handle = libvirt_connect($this->uri, false, $this->credentials);
        } else {
            $handle = libvirt_connect($this->uri, false);
        }

        if ($handle) {
            self::$handle[$this->uri] = $handle;
        } else {
            $this->setLastError();
        }
    }

    /**
     * Reads and sets the last error reported by libvirt.
     *
     * @return bool
     *  Always false.
     * @codeCoverageIgnore
     */
    private function setLastError()
    {
        $this->lastError = libvirt_get_last_error();
        return false;
    }

    /**
     * Translates internal domain state to human readable version.
     *
     * @param string $state
     *  Unstranslated state.
     * @return string
     *  Human readable state.
     */
    private function domainStateTranslate($state)
    {
        switch ($state) {
            case VIR_DOMAIN_RUNNING:
                return self::STATE_RUNNING;
            case VIR_DOMAIN_NOSTATE:
                return self::STATE_NOSTATE;
            case VIR_DOMAIN_BLOCKED:
                return self::STATE_BLOCKED;
            case VIR_DOMAIN_PAUSED:
                return self::STATE_PAUSED;
            case VIR_DOMAIN_SHUTDOWN:
                return self::STATE_SHUTDOWN;
            case VIR_DOMAIN_SHUTOFF:
                return self::STATE_SHUTOFF;
            case VIR_DOMAIN_CRASHED:
                return self::STATE_CRASHED;
        }

        return self::STATE_UNKNOWN;
    }

    /**
     * Gets domain info array for one or more domains.
     *
     * @param resource|string|bool $domain
     *  Domain resource, name or UUID.
     * @return array
     *  An associative array with basic domain info:
     *  'maxMem', 'memory', 'state', 'nrVirtCpu', 'cpuUsed'
     *  or false if an error occurred.
     * @codeCoverageIgnore
     */
    public function domainGetInfo($domain)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        try {
            $ret = libvirt_domain_get_info($dom);
        } catch (Exception $e) {
            $ret = false;
        }

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Update the domain's devices from the XML string
     *
     * @param resource|string|bool $domain
     *  Domain resource, name or UUID
     * @param string $xml
     *  XML string for update
     * @param null $flags
     *  Flags to update the device (VIR_DOMAIN_DEVICE_MODIFY_CURRENT, VIR_DOMAIN_DEVICE_MODIFY_LIVE,
     *  VIR_DOMAIN_DEVICE_MODIFY_CONFIG, VIR_DOMAIN_DEVICE_MODIFY_FORCE)
     * @return bool
     *  FALSE on error
     * @codeCoverageIgnore
     */
    public function attachDomainDevice($domain, $xml, $flags = null)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        if (!$flags) {
            $ret = libvirt_domain_attach_device($dom, $xml);
        } else {
            $ret = libvirt_domain_attach_device($dom, $xml, $flags);
        }

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Update the domain's devices from the XML string
     *
     * @param resource|string|bool $domain
     *  Domain resource, name or UUID
     * @param string $xml
     *  XML string for update
     * @param null $flags
     *  Flags to update the device (VIR_DOMAIN_DEVICE_MODIFY_CURRENT, VIR_DOMAIN_DEVICE_MODIFY_LIVE,
     *  VIR_DOMAIN_DEVICE_MODIFY_CONFIG, VIR_DOMAIN_DEVICE_MODIFY_FORCE)
     * @return bool
     *  FALSE on error
     * @codeCoverageIgnore
     */
    public function detachDomainDevice($domain, $xml, $flags = null)
    {
        $dom = $this->getDomainObject($domain);
        if (!$dom) {
            return false;
        }

        if (!$flags) {
            $ret = libvirt_domain_detach_device($dom, $xml);
        } else {
            $ret = libvirt_domain_detach_device($dom, $xml, $flags);
        }

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets storage pool resource handle
     *
     * @param resource|string $resName
     *  Storage resource or name.
     * @return resource
     *  Storage resource or false on failure.
     *  Use the getLastError() method to get actuall error message.
     * @codeCoverageIgnore
     */
    private function getStoragePoolResource($resName)
    {
        if (is_resource($resName)) {
            return $resName;
        }

        $ret = libvirt_storagepool_lookup_by_name(self::$handle[$this->uri], $resName);
        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets the network resource handle
     *
     * @param resource|string $resName
     *  Network resource or name.
     * @return resource
     *  Network resource or false on failure.
     *  Use the getLastError() method to get actuall error message.
     * @codeCoverageIgnore
     */
    private function getNetworkResource($resName)
    {
        if (is_resource($resName)) {
            return $resName;
        }

        $ret = libvirt_network_get(self::$handle[$this->uri], $resName);

        return $ret ? $ret : $this->setLastError();
    }


    /**
     * Sets the activity state of the network
     *
     * @param resource $resName
     *  Network resource or name.
     * @param boolean $active
     *  TRUE to activate, FALSE to deactivate
     * @return boolean
     *  TRUE if success, FALSE on error
     *  Use the getLastError() method to get actual error message.
     * @codeCoverageIgnore
     */
    public function setNetworkActive($resName, bool $active): bool
    {
        $res = $this->getNetworkResource($resName);
        if (!$res) {
            return false;
        }

        $active = (int) $active;
        $ret = libvirt_network_set_active($res, $active);

        return $ret ? $ret : $this->setLastError();
    }

    /**
     * Gets information about the hypervisor like name and version.
     *
     * @return array|bool
     *  <code>
     *      array(
     *          'hypervisor' => 'Hyper-V' | 'ESX',
     *          'major' => 6
     *          'minor' => 1,
     *          'release' => 123,
     *          'hypervisor_string' => 'Hyper-V 6.2.123'
     *      )
     *  </code>
     * @codeCoverageIgnore
     */
    public function getConnectionHypervisor()
    {
        return libvirt_connect_get_hypervisor(self::$handle[$this->uri]);
    }
}
