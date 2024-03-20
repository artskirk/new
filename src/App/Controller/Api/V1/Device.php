<?php

namespace Datto\App\Controller\Api\V1;

use Datto\Billing;
use Datto\Config\DeviceConfig;
use Datto\DirectToCloud\SupportZip;
use Datto\License\AgentLimit;
use Datto\Resource\DateTimeService;
use Datto\System\Health;
use Datto\System\HealthService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * API endpoint for device behavior.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Device implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var AgentLimit */
    private $agentLimit;

    /** @var Billing\Service */
    private $billingService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var HealthService */
    private $healthService;

    public function __construct(
        AgentLimit $agentLimit,
        Billing\Service $billingService,
        DateTimeService $dateTimeService,
        DeviceConfig $deviceConfig,
        HealthService $healthService
    ) {
        $this->agentLimit = $agentLimit;
        $this->billingService = $billingService;
        $this->dateTimeService = $dateTimeService;
        $this->deviceConfig = $deviceConfig;
        $this->healthService = $healthService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_INFO")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_INFO")
     * @return array
     */
    public function get(): array
    {
        $deviceExpirationDate = $this->billingService->getExpirationDate() ?? 0;
        $deviceIsOutOfService = $this->billingService->isOutOfService();
        $canAddAgents = $this->agentLimit->canAddAgents();
        $canUnpauseAgents = $this->agentLimit->canUnpauseAgent();
        $timezone = $this->dateTimeService->format('T');
        $imageVersion = $this->deviceConfig->getImageVersion();

        return [
            "deviceExpirationDate" => $deviceExpirationDate,
            "deviceOutOfService" => $deviceIsOutOfService,
            "canAddAgents" => $canAddAgents,
            "canUnpauseAgents" => $canUnpauseAgents,
            "timezone" => $timezone,
            "imageVersion" => $imageVersion
        ];
    }

    /**
     * API endpoint to return a list of normalized system healthcheck scores.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_INFO")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_INFO")
     *
     * @return Health
     */
    public function healthcheck(): Health
    {
        return $this->healthService->calculateHealthScores();
    }

    /**
     * API endpoint to confirm device is reachable.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_INFO")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_INFO")
     *
     * @return bool
     */
    public function ping(): bool
    {
        return true;
    }

    /**
     * API endpoint to return a support zip file containing log info from a Cloud Device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_INFO")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_INFO")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^$|^[a-f\d\-\_\.]+$~"),
     *   "includeRotatedLogs" = @Symfony\Component\Validator\Constraints\Type("bool"),
     *   "rotatedLogsDaysBack" = @Symfony\Component\Validator\Constraints\PositiveOrZero()
     * })
     * @return array
     */
    public function getSupportZip(string $agentKeyName, bool $includeRotatedLogs, int $rotatedLogsDaysBack): array
    {
        $retArray = [
            'success' => false,
            'truncated' => false,
            'error' => '',
            'agentKeyName' => $agentKeyName,
            'includeRotatedLogs' => $includeRotatedLogs,
            'rotatedLogsDaysBack' => $rotatedLogsDaysBack,
            'zipName' => '',
            'zipContent' => '',
            'zipMd5' => ''
        ];

        if ($this->deviceConfig->isCloudDevice()) {
            $supportZip = new SupportZip();
            try {
                $supportZip->build('/tmp', $agentKeyName, $includeRotatedLogs, $rotatedLogsDaysBack);
                $retArray['zipContent'] = $supportZip->getBase64();
                $retArray['zipName'] = basename($supportZip->getZipPath());
                $retArray['zipMd5'] = $supportZip->getMd5sum();
                $retArray['truncated'] = $supportZip->isTruncated();
                $retArray['success'] = true;
            } catch (\Throwable $e) {
                $this->logger->debug('GSZ0001 Could not create support zip.', ['exception' => $e]);
                throw $e;
            } finally {
                $supportZip->cleanup();
            }
        } else {
            $retArray['error'] = 'Not implemented. Not a cloud device.';
        }

        return $retArray;
    }
}
