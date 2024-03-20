<?php

namespace Datto\Verification\Notification;

use Datto\Asset\AssetService;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Common\Utility\Filesystem;
use Exception;
use Datto\Config\DeviceConfig;
use Datto\Cloud\JsonRpcClient;
use Throwable;

/**
 * Upload the screenshot to the Partner Portal via Device-Web
 *
 * @author Fury Christ <jchrist@datto.com>
 * @author Afeique Sheikh <ashiekh@datto.com>
 */
class UploadScreenshot extends VerificationNotification
{
    const UPLOAD_SCREENSHOT_TO_WEBSERVER_ENDPOINT = 'v1/device/asset/agent/screenshot/upload';
    const DEVICE_ID = 'deviceID';
    const MAX_SCREENSHOT_TRIES = 3;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var JsonRpcClient */
    private $client;

    /** @var AssetService */
    private $assetService;

    /** @var RecoveryPointInfoService */
    private $recoveryPointInfoService;

    public function __construct(
        Filesystem $filesystem,
        DeviceConfig $deviceConfig,
        JsonRpcClient $client,
        AssetService $assetService,
        RecoveryPointInfoService $recoveryPointInfoService
    ) {
        $this->filesystem = $filesystem;
        $this->deviceConfig = $deviceConfig;
        $this->client = $client;
        $this->assetService = $assetService;
        $this->recoveryPointInfoService = $recoveryPointInfoService;
    }

    public function commit()
    {
        try {
            $screenshotFilePath = $this->verificationResults->getVerificationContext()->getScreenshotImagePath();
            $this->logger->info(
                'SPM1025 Uploading screenshot to device-web.',
                ['screenshotFilePath' => $screenshotFilePath]
            );
            $postData = $this->prepareScreenshot();

            $this->logger->debug(
                "SPM1032 Sending verification information to device-web",
                array_diff_key($postData, ['file' => null]) // remove the 'file' key to avoid logging file data
            );

            $uploadSuccess = false;
            for ($i = 0; $i < static::MAX_SCREENSHOT_TRIES && !$uploadSuccess; $i++) {
                $uploadSuccess = $this->client->queryWithId(
                    static::UPLOAD_SCREENSHOT_TO_WEBSERVER_ENDPOINT,
                    $postData
                );
            }

            if (!$uploadSuccess) {
                $this->logger->error("SPM1026 Could not upload screenshot to cloud via device-web");
            }
        } catch (Throwable $e) {
            $this->logger->info(
                'SPM1027 Screenshot upload to Partners Portal via Device-Web failed.',
                ['exception' => $e]
            );
        }
    }

    private function prepareScreenshot(): array
    {
        $verificationContext = $this->verificationResults->getVerificationContext();
        $uuid = $verificationContext->getAgent()->getUuid();
        $screenshotEpoch = $verificationContext->getSnapshotEpoch();
        $screenshotFilePath = $verificationContext->getScreenshotImagePath();
        $screenshotErrorTextPath = $verificationContext->getScreenshotErrorTextPath();
        $screenshotSuccess = $this->verificationResults->getScreenshotSuccessful();
        $screenshotAnalysis = $this->verificationResults->getScreenshotAnalysis();
        $analysisSuccess = $screenshotAnalysis === VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;
        $scriptSuccess = $this->verificationResults->getScriptSuccess();

        $failText = VerificationResults::SCREENSHOT_ANALYSIS_NO_FAILURE;
        if ($this->filesystem->exists($screenshotErrorTextPath)) {
            $this->logger->info(
                'SPM1030 Found screenshot error text.',
                ['screenshotErrorText' => $screenshotErrorTextPath]
            );
            $failText = $this->filesystem->fileGetContents($screenshotErrorTextPath);
        }

        // BCDR-23657: no screenshot file will exist if HIR and Verification was skipped due to pending OS update so ignore that case
        if ($this->filesystem->exists($screenshotFilePath)) {
            $this->logger->info('SPM1031 Found screenshot file.', ['screenshotFilePath' => $screenshotFilePath]);
        } elseif (!$verificationContext->getOsUpdatePending()) {
            throw new Exception("Screenshot file does not exist: $screenshotFilePath");
        }

        $meta = [
            static::DEVICE_ID => intval($this->deviceConfig->get(static::DEVICE_ID)),
            'hostname' => $uuid,
            'snapshotID' => $screenshotEpoch,
            'result' => intval($screenshotSuccess && $analysisSuccess && $scriptSuccess),
            'failText' => $failText,
            'agentType' => $this->verificationResults->getAgent()->getPlatform()->value()
        ];
        // BCDR-23657: If no screenshot file exists, fileGetContents returns false which is encoded to empty string ''
        $postData = [
            static::DEVICE_ID => $this->deviceConfig->get(static::DEVICE_ID),
            'hostname' => $uuid,
            'file' => base64_encode($this->filesystem->fileGetContents($screenshotFilePath)),
            'meta' => json_encode($meta),
            'verification' => $this->getVerificationInformation(),
        ];

        return $postData;
    }

    /**
     * Reads the latest Asset information and grabs the Advanced Verification results
     *
     * @return array
     */
    private function getVerificationInformation(): array
    {
        // The asset will need to be re-read in as the one in the context is out of date at this point
        $assetKey = $this->verificationResults->getAgent()->getKeyName();
        $snapshotEpoch = $this->verificationResults->getSnapshotEpoch();
        $asset = $this->assetService->get($assetKey);
        $recoveryPoint = $this->recoveryPointInfoService->get($asset, $snapshotEpoch, false);
        $verificationContext = $this->verificationResults->getVerificationContext();
        if ($recoveryPoint) {
            $recoveryArray = $recoveryPoint->toArray();
            $verification = $recoveryArray['verification'];
            $verification["advanced"]["isOsUpdatePending"] = false;
            $verification["local"]["isOsUpdatePending"] = false;
            if ($verificationContext->getOsUpdatePending()) {
                $verification["advanced"]["isOsUpdatePending"] = true;
                $verification["local"]["isOsUpdatePending"] = true;
                $verification["advanced"]["hasError"] = true;
                $verification["local"]["hasError"] = true;
            }
            return $verification;
        }

        return [];
    }
}
