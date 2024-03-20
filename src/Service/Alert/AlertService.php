<?php

namespace Datto\Service\Alert;

use Datto\AlertSchemas\Alert;
use Datto\AlertSchemas\BackupFailedAlert;
use Datto\AlertSchemas\ScreenshotAlert;
use Datto\AlertSchemas\ScreenshotFailureAlert;
use Datto\Asset\AssetService;
use Datto\Metrics\Collector;
use Datto\Cloud\JsonRpcClient;
use Datto\Feature\FeatureService;
use Datto\Common\Utility\Filesystem;
use Datto\Resource\DateTimeService;
use Psr\Log\LoggerAwareInterface;
use Datto\Log\LoggerAwareTrait;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Metrics\Metrics;
use Throwable;

/**
 * Handles sending alerts to device-web json-rpc endpoints
 *
 * @author Kiran Bachu <kbachu@datto.com>
 */
class AlertService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DEVICE_ALERT_ENDPOINT = 'v1/device/alert';

    private JsonRpcClient $deviceWebClient;
    private AssetService $assetService;
    private Collector $collector;
    private FeatureService $featureService;
    private Filesystem $filesystem;
    private DateTimeService $dateTimeService;
    private ProtectedMachineFactory $protectedMachineFactory;

    public function __construct(
        JsonRpcClient $deviceWebClient,
        AssetService $assetService,
        Collector $collector,
        FeatureService $featureService,
        Filesystem $filesystem,
        DateTimeService $dateTimeService,
        ProtectedMachineFactory $protectedMachineFactory
    ) {
        $this->deviceWebClient = $deviceWebClient;
        $this->assetService = $assetService;
        $this->collector = $collector;
        $this->featureService = $featureService;
        $this->filesystem = $filesystem;
        $this->dateTimeService = $dateTimeService;
        $this->protectedMachineFactory = $protectedMachineFactory;
    }

    public function sendBackupFailedAlert(
        string $assetKey,
        string $logs = ''
    ): bool {
        $asset = $this->assetService->get($assetKey);
        $protectedMachine = $this->protectedMachineFactory->fromAsset($asset);

        $alert = new BackupFailedAlert($protectedMachine, $logs, $this->dateTimeService->getTime());

        return $this->sendAlert($alert);
    }

    public function sendScreenshotAlert(
        string $assetKey,
        string $screenshotFile,
        int $snapshotEpoch,
        bool $screenshotSuccess
    ): bool {
        $asset = $this->assetService->get($assetKey);
        $protectedMachine = $this->protectedMachineFactory->fromAsset($asset);
        $screenshot = $this->filesystem->fileGetContents($screenshotFile);
        
        if ($screenshotSuccess) {
            $alert = new ScreenshotAlert(
                $protectedMachine,
                base64_encode($screenshot),
                $snapshotEpoch,
                $snapshotEpoch
            );
        } else {
            $errorTextPath = ScreenshotFileRepository::getScreenshotErrorTextPath($assetKey, $snapshotEpoch);
            $errorTextExists = $this->filesystem->exists($errorTextPath);
            $errorText = $errorTextExists ? $this->filesystem->fileGetContents($errorTextPath) : null;
            $alert = new ScreenshotFailureAlert(
                $protectedMachine,
                $errorText,
                base64_encode($screenshot),
                $snapshotEpoch,
                strval($snapshotEpoch)
            );
        }
        return $this->sendAlert($alert);
    }

    private function sendAlert(Alert $alert): bool
    {
        $context = [
            'alertType' => $alert->getType()
        ];

        try {
            if (!$this->featureService->isSupported(FeatureService::FEATURE_ALERTVIAJSONRPC)) {
                $this->logger->debug('ALR0001 Alert Service not enabled', $context);

                return false;
            }

            $this->logger->debug('ALR0002 Sending Alert', $context);
            $this->deviceWebClient->queryWithId(self::DEVICE_ALERT_ENDPOINT, $alert->jsonSerialize());

            $this->collector->increment(Metrics::ALERT_SENT, $context);
            $this->logger->debug('ALR0003 Alert sent successfully', $context);
            
            return true;
        } catch (Throwable $e) {
            $this->collector->increment(Metrics::ALERT_FAILURE, $context);
            $context['exception'] = $e;
            $this->logger->error('ALR0004 Unable to send alert', $context);
            
            return false;
        }
    }
}
