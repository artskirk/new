<?php

namespace Datto\License;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Windows\Serializer\ShadowProtectLicenseInfoSerializer;
use Datto\Cloud\JsonRpcClient;
use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Deals with ShadowProtect licenses.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class ShadowProtectLicenseManager
{
    const RELEASE_RESTRICTION_INTERVAL = 2592000; // 30 Days in seconds: 30 * 24 * 60 * 60

    /** @var string */
    private $assetKey;

    /** @var string */
    private $licensePath;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var JsonRpcClient */
    private $client;

    /** @var Filesystem */
    private $filesystem;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var ShadowProtectLicenseInfoSerializer */
    private $serializer;

    /**
     * @param string $assetKey
     * @param DeviceLoggerInterface|null $logger
     * @param JsonRpcClient|null $client
     * @param Filesystem|null $filesystem
     * @param DateTimeService|null $dateTimeService
     */
    public function __construct(
        $assetKey,
        DeviceLoggerInterface $logger = null,
        JsonRpcClient $client = null,
        Filesystem $filesystem = null,
        DateTimeService $dateTimeService = null
    ) {
        $this->assetKey = $assetKey;
        $this->logger = $logger ?: LoggerFactory::getAssetLogger($assetKey);
        $this->client = $client ?: new JsonRpcClient();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
        $this->serializer = new ShadowProtectLicenseInfoSerializer();
        $this->licensePath = Agent::KEYBASE . $assetKey . '.shadowProtectLicenseInfo';
    }

    /**
     * Release the ShadowProtect license for the agent.
     */
    public function release()
    {
        $this->checkForLicenseReleasability();
        $this->releaseUnconditionally();
    }

    /**
     * Release the ShadowProtect license for the agent, regardless of the release restrictions.
     */
    public function releaseUnconditionally()
    {
        try {
            $this->logger->info('SLM0001 Attempting to release ShadowProtect license');
            $this->processReleaseQuery();
        } catch (Exception $e) {
            $this->logger->error('SLM0003 There was a problem releasing the ShadowProtect license', ['exception' => $e]);
            throw $e;
        }
        $this->logger->info('SLM0002 Successfully released the ShadowProtect license');
    }

    /**
     * Is it OK to release the ShadowProtect license?
     *
     * @return bool
     */
    public function canReleaseLicense()
    {
        $timeSinceLastRelease = $this->dateTimeService->getTime() - $this->getLastReleaseTime();
        return $timeSinceLastRelease > static::RELEASE_RESTRICTION_INTERVAL;
    }

    /**
     * Check that the agent's license is releasable, and throw if it isn't.
     *
     * the license was released via this process)
     */
    private function checkForLicenseReleasability()
    {
        if (!$this->canReleaseLicense()) {
            $this->logger->error('SLM0004 The ShadowProtect license cannot be released at this time');
            throw new Exception('The ShadowProtect license cannot be released at this time');
        }
    }

    private function processReleaseQuery()
    {
        try {
            $result = $this->client->queryWithId(
                'v1/device/asset/license/shadowProtect/release',
                array('assetKey' => $this->assetKey)
            );
        } catch (Exception $e) {
            $errorMessage = 'Client query failed with message: ' . $e->getMessage();
            throw new Exception($errorMessage, 0, $e);
        }

        if (isset($result['success']) && $result['success']) {
            $this->updateLicenseInfo();
        } else {
            $errorMessage = isset($result['errorMessage']) ?
                $result['errorMessage'] :
                'Could not determine error from the returned results';
            throw new Exception($errorMessage);
        }
    }

    /**
     * Get the last release time of the ShadowProtect license for the agent.
     *
     * @return int Last release time or 0 if not available
     */
    private function getLastReleaseTime()
    {
        if ($this->filesystem->exists($this->licensePath)) {
            $fileContents = $this->filesystem->fileGetContents($this->licensePath);
            return $this->serializer->unserialize($fileContents);
        } else {
            return 0;
        }
    }

    /**
     * Update the license info file with the current time.
     */
    private function updateLicenseInfo()
    {
        $serializedInfo = $this->serializer->serialize($this->dateTimeService->getTime());
        $this->filesystem->filePutContents($this->licensePath, $serializedInfo);
    }
}
