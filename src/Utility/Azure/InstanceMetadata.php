<?php


namespace Datto\Utility\Azure;

use Datto\Log\LoggerAwareTrait;
use Datto\Util\RetryAttemptsExhaustedException;
use Datto\Util\RetryHandler;
use Datto\Utility\ByteUnit;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

/**
 * Utility class to read from Azure IMDS (Instance Metadata Service). A VM can make a request to a magic,
 * typically non-routable IP which Azure intercepts and responds with various metadata about the VM.
 *
 * https://docs.microsoft.com/en-us/azure/virtual-machines/linux/instance-metadata-service
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class InstanceMetadata implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const FIELD_COMPUTE = 'compute';
    public const FIELD_SUBSCRIPTION_ID = 'subscriptionId';
    public const FIELD_RESOURCE_GROUP_NAME = 'resourceGroupName';
    public const FIELD_VM_SIZE = 'vmSize';

    private const IMDS_ROOT = 'http://169.254.169.254/metadata/instance';
    private const ALL_URL = self::IMDS_ROOT . '?api-version=2020-06-01';
    private const TAG_LIST_URL = self::IMDS_ROOT . '/compute/tagsList?api-version=2020-06-01';
    private const VM_ID_URL = self::IMDS_ROOT . '/compute/vmId?api-version=2020-06-01&format=text';
    private const STORAGE_PROFILE_URL = self::IMDS_ROOT . '/compute/storageProfile?api-version=2021-02-01';
    private const DATA_DISKS_URL = self::IMDS_ROOT . '/compute/storageProfile/dataDisks?api-version=2020-06-01';
    private const NETWORK_INTERFACES_URL = self::IMDS_ROOT . '/network/interface?api-version=2020-06-01';
    private const DATACENTER_LOCATION_URL = self::IMDS_ROOT . '/compute/location?api-version=2020-06-01&format=text';

    /**
     * @var RetryHandler
     */
    private $retryHandler;

    /**
     * @var bool
     */
    private $cachedIsSupported = null;

    /**
     * @param RetryHandler|null $retryHandler
     */
    public function __construct(RetryHandler $retryHandler = null)
    {
        $this->retryHandler = $retryHandler;
    }

   /**
     * Check if IMDS is supported on this system.
     */
    public function isSupported()
    {
        if ($this->cachedIsSupported !== null) {
            return $this->cachedIsSupported;
        }

        try {
            $this->doRequest(self::VM_ID_URL);
        } catch (IMDSNotAvailableException $e) {
            $this->cachedIsSupported = false;
            return false;
        }

        $this->cachedIsSupported = true;
        return true;
    }

    /**
     * Get everything from IMDS.
     */
    public function get(): array
    {
        $content = $this->doRequest(self::ALL_URL);

        $result = json_decode($content, true);
        if ($result === null) {
            throw new Exception('IMDS endpoint returned a non-json-decodable response');
        }

        return $result;
    }

    /**
     * Get network interfaces from IMDS.
     */
    public function getInterfaces(): array
    {
        $content = $this->doRequest(self::NETWORK_INTERFACES_URL);

        if ($content === false) {
            throw new Exception('Could not reach IMDS network interfaces endpoint');
        }

        $interfaceItems = json_decode($content, true);
        if ($interfaceItems === null) {
            throw new Exception('IMDS network interface endpoint returned a non-json-decodable response');
        }

        return $interfaceItems;
    }

    /**
     * Get a key-value array of tags from IMDS.
     *
     * @return array
     * @throws Exception
     */
    public function getTags(): array
    {
        $content = $this->doRequest(self::TAG_LIST_URL);

        $tagItems = json_decode($content, true);
        if ($tagItems === null) {
            throw new Exception('IMDS tag endpoint returned a non-json-decodable response');
        }

        $tags = [];

        foreach ($tagItems as $tagItem) {
            $name = $tagItem['name'];
            $value = $tagItem['value'];

            $tags[$name] = $value;
        }

        return $tags;
    }

    /**
     * Get the list of attached data disks
     *
     * @return InstanceMetadataDisk[]
     * @throws Exception
     */
    public function getDataDisks(): array
    {
        $content = $this->doRequest(self::DATA_DISKS_URL);

        if ($content === false) {
            throw new Exception('Could not reach IMDS data disks endpoint');
        }

        $rawDisks = json_decode($content, true);
        if ($rawDisks === null) {
            throw new Exception('IMDS data disks endpoint returned a non-json-decodable response');
        }

        $disks = [];

        foreach ($rawDisks as $rawDisk) {
            $disks[] = InstanceMetadataDisk::fromInstanceMetadataResponse($rawDisk);
        }
        $this->logger->info("IMD0001 Detected instance metadata data disks", ['disks' => $disks]);

        return $disks;
    }

    public function getStorageProfile(): InstanceMetadataStorageProfile
    {
        $content = $this->doRequest(self::STORAGE_PROFILE_URL);

        if ($content === false) {
            throw new Exception('Could not reach IMDS storage profile endpoint');
        }

        $data = json_decode($content, true);
        if ($data === null) {
            throw new Exception('IMDS storage profile endpoint returned a non-json-decodable response');
        }

        if (!isset($data['resourceDisk']['size'])) {
            throw new Exception('Expected IMDS storage profile to have resource disk');
        }

        return new InstanceMetadataStorageProfile(
            (int)ByteUnit::MIB()->toByte((int)$data['resourceDisk']['size'])
        );
    }

    /**
     * Get the region identifier (if any) associated with this instance
     *
     * @return string Value of location property in JSON returned by IMDS or empty string
     */
    public function getLocation(): string
    {
        $content = $this->doRequest(self::DATACENTER_LOCATION_URL);

        if (empty($content)) {
            throw new Exception('IMDS location endpoint returned an unexpectedly null, empty, or false value for location');
        }

        $content = trim($content);

        $this->logger->debug("IMD0002 Retrieved instance location/region data from IMDS", ['location' => $content]);

        return $content;
    }

    private function doRequest(string $url)
    {
        $this->logger->debug('IMD0004 Attempting to query IMDS endpoint', [
            "url" => $url
        ]);

        $opts = [
            'http' => [
                'method' => "GET",
                'header' => 'Metadata: true'
            ]
        ];

        $context = stream_context_create($opts);

        try {
            return $this->retryHandler->executeAllowRetry(function () use ($url, $context) {
                $result = file_get_contents($url, false, $context);

                if ($result === false) {
                    $this->logger->error('IMD0003 Could not reach IMDS endpoint', [
                        "url" => $url,
                        "context" => $context,
                        "http_response_header" => $http_response_header
                    ]);

                    throw new IMDSNotAvailableException('Could not reach IMDS endpoint');
                }
                return $result;
            });
        } catch (RetryAttemptsExhaustedException $e) {
            throw $e->getPrevious() ?? $e;
        }
    }
}
