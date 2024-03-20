<?php

namespace Datto\DirectToCloud\Creation;

use Datto\App\Console\Command\Agent\DirectToCloud\CreateCommand;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\DatasetPurpose;
use Datto\Asset\Retention;
use Datto\Asset\Serializer\VerificationScheduleSerializer;
use Datto\Asset\VerificationSchedule;
use Datto\Cloud\SpeedSync;
use Datto\Config\DeviceConfig;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Datto\DirectToCloud\Creation\Stages\PersistAgent;
use Datto\DirectToCloud\Creation\Stages\ProvisionStorage;
use Datto\DirectToCloud\Creation\Stages\UpdateCloud;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionFailureType;
use Datto\Utility\Screen;
use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Service for creating DTC Agent assets.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Service implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var int Hardcoded retention for DTC agents of 1 year for TBR */
    const TIME_BASED_RETENTION_YEARS = 1;

    const STATE_RUNNING = 'running';
    const STATE_READY = 'ready';
    const STATE_NONE = null;

    const WORKER_NAME_FORMAT = 'directtocloud-create-agent-%s';

    private DeviceConfig $deviceConfig;
    private AssetService $assetService;
    private AgentService $agentService;
    private VerificationScheduleSerializer $verificationScheduleSerializer;
    private Screen $screen;
    private Collector $collector;

    private ProvisionStorage $provisionStorage;
    private PersistAgent $persistAgent;
    private UpdateCloud $updateCloud;

    public function __construct(
        DeviceConfig $deviceConfig,
        AssetService $assetService,
        AgentService $agentService,
        VerificationScheduleSerializer $verificationScheduleSerializer,
        Screen $screen,
        Collector $collector,
        ProvisionStorage $provisionStorage,
        PersistAgent $persistAgent,
        UpdateCloud $updateCloud
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->assetService = $assetService;
        $this->agentService = $agentService;
        $this->verificationScheduleSerializer = $verificationScheduleSerializer;
        $this->screen = $screen;
        $this->collector = $collector;
        $this->provisionStorage = $provisionStorage;
        $this->persistAgent = $persistAgent;
        $this->updateCloud = $updateCloud;
    }

    public function createArchivedAgent(
        AssetMetadata $assetMetadata
    ): Agent {
        $context = new Context(
            $assetMetadata,
            Retention::createDefaultAzureLocal(), // Doesn't matter since retention does not run on archived agents
            Retention::createInfinite(), // Doesn't matter since retention does not run on archived agents
            $this->deviceConfig->getResellerId(),
            new VerificationSchedule(), // Doesn't matter since we won't have backups to verify
            SpeedSync::TARGET_CLOUD,
            false,
            true
        );

        return $this->doCreateAgent($context);
    }

    public function createAgent(
        string $agentUuid,
        string $hostname,
        Retention $localRetention,
        Retention $offsiteRetention,
        int $resellerId,
        bool $useExistingDataset,
        string $operatingSystem
    ): Agent {
        $assetMetadata = new AssetMetadata(
            $agentUuid,
            $agentUuid,
            $hostname,
            $hostname,
            DatasetPurpose::AGENT(),
            AgentPlatform::DIRECT_TO_CLOUD(),
            $operatingSystem,
            null
        );

        $verificationSchedule = $this->createVerificationSchedule();
        $this->logger->info(
            "DCS0009 Using verification schedule",
            $this->verificationScheduleSerializer->serialize($verificationSchedule)
        );

        $context = new Context(
            $assetMetadata,
            $localRetention,
            $offsiteRetention,
            $resellerId,
            $verificationSchedule,
            SpeedSync::TARGET_CLOUD,
            $useExistingDataset,
            false
        );

        return $this->doCreateAgent($context);
    }

    public function createAgentAsync(
        string $agentUuid,
        string $hostname,
        Retention $localRetention,
        Retention $offsiteRetention,
        int $resellerId,
        bool $useExistingDataset,
        bool $hasSubscription,
        string $operatingSystem
    ): array {
        $command = [
            'snapctl',
            CreateCommand::getDefaultName(),
            "--agent-uuid=$agentUuid",
            "--hostname=$hostname",
            "--reseller-id=$resellerId",
            "--local-retention=" . self::serializeRetentionForCommand($localRetention),
            "--offsite-retention=" . self::serializeRetentionForCommand($offsiteRetention),
            "--operating-system=$operatingSystem"
        ];
        if ($useExistingDataset) {
            array_push($command, '--use-existing-dataset');
        }
        if ($hasSubscription) {
            array_push($command, '--has-subscription');
        }

        $name = sprintf(self::WORKER_NAME_FORMAT, $agentUuid);

        $status = $this->getStatus($agentUuid);
        if ($status['state'] === self::STATE_NONE) {
            $this->screen->runInBackground($command, $name);
        }

        //FIXME: if already running, assert that the contexts match.

        return $this->getStatus($agentUuid);
    }

    /**
     * @param string $agentUuid
     * @return array
     */
    public function getStatus(string $agentUuid): array
    {
        $assetKey = null;

        if ($this->screen->isScreenRunning(sprintf(self::WORKER_NAME_FORMAT, $agentUuid))) {
            $state = self::STATE_RUNNING;
        } elseif ($this->agentService->exists($agentUuid)) {
            $state = self::STATE_READY;
            $assetKey = $this->agentService->get($agentUuid)->getKeyName();
        } else {
            $state = self::STATE_NONE;
        }

        return [
            'assetKey' => $assetKey,
            'state' => $state
        ];
    }

    public static function serializeRetentionForCommand(Retention $retention): string
    {
        return sprintf(
            '%s,%s,%s,%s',
            $retention->getDaily(),
            $retention->getWeekly(),
            $retention->getMonthly(),
            $retention->getMaximum()
        );
    }

    public static function deserializeRetentionForCommand(string $serializedRetention): Retention
    {
        $retentionValues = explode(',', $serializedRetention);
        if (count($retentionValues) !== 4) {
            throw new InvalidArgumentException('Local and offsite retention policy should be formatted as "0,1,2,3"');
        }

        return new Retention(
            (int)$retentionValues[0],
            (int)$retentionValues[1],
            (int)$retentionValues[2],
            (int)$retentionValues[3]
        );
    }

    private function doCreateAgent(Context $context): Agent
    {
        $this->logger->setAssetContext($context->getAssetKey());

        $metricContext = [
            'archived' => $context->isArchived() ? 'true' : 'false'
        ];

        try {
            $this->logger->info("DCS0001 Starting agent creation process ...", [
                'createContext' => $context->getLogContext()
            ]);
            $this->collector->increment(Metrics::DTC_AGENT_CREATE_STARTED, $metricContext);

            $this->validateContext($context);
            $this->execute($context);

            $this->logger->info('DCS0002 Agent creation complete.', [
                'assetKey' => $context->getAssetKey()
            ]);
            $this->collector->increment(Metrics::DTC_AGENT_CREATE_SUCCESS, $metricContext);

            return $context->getAgentOrThrow();
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('DCS0003 Agent creation parameters were invalid.', [
                'exception' => $e
            ]);
            $this->collector->increment(Metrics::DTC_AGENT_CREATE_INVALID, $metricContext);
            throw $e;
        } catch (Throwable $e) {
            $this->logger->critical('DCS0004 Agent creation failed', [
                'exception' => $e
            ]);
            $this->collector->increment(Metrics::DTC_AGENT_CREATE_FAILED, $metricContext);
            throw $e;
        }
    }

    private function execute(Context $context): void
    {
        $transaction = new Transaction(TransactionFailureType::STOP_ON_FAILURE(), $this->logger, $context);

        $transaction->add($this->provisionStorage);
        $transaction->add($this->persistAgent);
        $transaction->add($this->updateCloud);

        $transaction->commit();
    }

    private function validate(
        string $assetKey,
        string $hostname
    ): void {
        if ($this->assetService->exists($assetKey)) {
            throw new InvalidArgumentException("Asset already exists with the key: " . $assetKey);
        }

        if (empty($hostname)) {
            throw new InvalidArgumentException("Hostname length must be greater than 0");
        }
    }

    private function validateContext(Context $context): void
    {
        $this->validate(
            $context->getAssetKey(),
            $context->getAssetMetadata()->getHostname()
        );
    }

    /**
     * @return VerificationSchedule
     */
    private function createVerificationSchedule(): VerificationSchedule
    {
        if ($this->deviceConfig->isAzureDevice()) {
            return new VerificationSchedule(
                VerificationSchedule::CUSTOM_SCHEDULE,
                WeeklySchedule::createAzureRandomSchedule()
            );
        }
        return new VerificationSchedule(
            VerificationSchedule::FIRST_POINT,
            null
        );
    }
}
