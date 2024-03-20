<?php

namespace Datto\Service\Security;

use Datto\Cloud\JsonRpcClient;
use Datto\Common\Resource\Filesystem;
use Datto\Common\Resource\ProcessFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Service to handle intrusion detection software on boot if applicable.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class IntrusionDetection implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const CONFIG_FILE_PATH = '/opt/redcanary/config.json';

    const RESULT_ALREADY_CONFIGURED = 'already_configured';
    const RESULT_CONFIGURED = 'configured';

    /** @var JsonRpcClient */
    private $client;

    /** @var Filesystem */
    private $filesystemResource;

    /** @var FeatureService */
    private $featureService;

    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(
        JsonRpcClient $client,
        Filesystem $filesystemResource,
        FeatureService $featureService,
        ProcessFactory $processFactory
    ) {
        $this->client = $client;
        $this->filesystemResource = $filesystemResource;
        $this->featureService = $featureService;
        $this->processFactory = $processFactory;
    }

    public function configure(string $overrideKey = null): string
    {
        $this->featureService->assertSupported(FeatureService::FEATURE_INTRUSION_DETECTION);

        $this->logger->info('IDS0001 Configuring RedCanary');

        try {
            $configured = $this->isConfigured();
            if (!$configured) {
                if ($overrideKey) {
                    $this->logger->debug('IDS0003 Using user provided key');
                    $key = $overrideKey;
                } else {
                    $this->logger->debug('IDS0004 Fetching key from device-web');
                    $key = $this->getKeyFromCloud();
                }

                $config = [
                    'access_token' => $key,
                    'subscription_plan' => 'Managed',
                    'cpu_limit_model' => 'irix'
                ];
                $this->filesystemResource->filePutContents(self::CONFIG_FILE_PATH, json_encode($config));

                $this->logger->info('IDS0005 RedCanary configured successfully');
            } else {
                $this->logger->info('IDS0002 RedCanary is already configured');
            }

            $this->logger->info('IDS0007 Starting the RedCanary service');
            $startService = $this->processFactory->get(['sudo', 'systemctl', 'start', 'cfsvcd.service']);
            $startService->mustRun();
            $this->logger->info('IDS0007 RedCanary service successfully started');

            return $configured ? self::RESULT_ALREADY_CONFIGURED : self::RESULT_CONFIGURED;
        } catch (\Throwable $e) {
            $this->logger->error('IDS0006 RedCanary configuration failed', [
                'message' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function isConfigured(): bool
    {
        return $this->filesystemResource->exists(self::CONFIG_FILE_PATH);
    }

    private function getKeyFromCloud(): string
    {
        return $this->client->queryWithId('v1/device/intrusiondetection/getKey');
    }
}
