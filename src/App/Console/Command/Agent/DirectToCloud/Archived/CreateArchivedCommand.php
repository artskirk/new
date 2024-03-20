<?php

namespace Datto\App\Console\Command\Agent\DirectToCloud\Archived;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Encryption\EncryptionKeyStashRecord;
use Datto\Asset\DatasetPurpose;
use Datto\DirectToCloud\Creation\AssetMetadata;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Datto\DirectToCloud\Creation\Service;
use Datto\Feature\FeatureService;

class CreateArchivedCommand extends AbstractCommand
{
    protected static $defaultName = 'agent:directtocloud:archived:create';

    const OPTION_ASSET_KEY = 'asset-key';
    const OPTION_ASSET_UUID = 'asset-uuid';
    const OPTION_HOSTNAME = 'hostname';
    const OPTION_FQDN = 'fqdn';
    const OPTION_DATASET_PURPOSE = 'dataset-purpose';
    const OPTION_AGENT_PLATFORM = 'agent-platform';
    const OPTION_OPERATING_SYSTEM = 'operating-system';
    const OPTION_ENCRYPTION_KEY_STASH = 'encryption-key-stash';

    const OPTIONS = [
        self::OPTION_ASSET_KEY,
        self::OPTION_ASSET_UUID,
        self::OPTION_HOSTNAME,
        self::OPTION_FQDN,
        self::OPTION_DATASET_PURPOSE,
        self::OPTION_AGENT_PLATFORM,
        self::OPTION_OPERATING_SYSTEM
    ];

    /** @var Service */
    private $creationService;

    public function __construct(
        Service $creationService
    ) {
        parent::__construct();

        $this->creationService = $creationService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ASSETS,
            FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Create an archived agent on a CC4PC/DCMA device')
            ->addOption(self::OPTION_ASSET_KEY, null, InputOption::VALUE_REQUIRED, 'Asset Key')
            ->addOption(self::OPTION_ASSET_UUID, null, InputOption::VALUE_REQUIRED, 'Asset UUID')
            ->addOption(self::OPTION_HOSTNAME, null, InputOption::VALUE_REQUIRED, 'Hostname string for the agent')
            ->addOption(self::OPTION_FQDN, null, InputOption::VALUE_REQUIRED, 'FQDN string for the agent')
            ->addOption(self::OPTION_DATASET_PURPOSE, null, InputOption::VALUE_REQUIRED, 'Dataset purpose string for the agent')
            ->addOption(self::OPTION_AGENT_PLATFORM, null, InputOption::VALUE_REQUIRED, 'Agent platform string for the agent')
            ->addOption(self::OPTION_OPERATING_SYSTEM, null, InputOption::VALUE_REQUIRED, 'Operating system string for the agent')
            ->addOption(self::OPTION_ENCRYPTION_KEY_STASH, null, InputOption::VALUE_REQUIRED, 'Encryption key stash in JSON format');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getOption(self::OPTION_ASSET_KEY);
        $assetUuid = $input->getOption(self::OPTION_ASSET_UUID);
        $hostname = $input->getOption(self::OPTION_HOSTNAME);
        $fqdn = $input->getOption(self::OPTION_FQDN);
        $datasetPurpose = DatasetPurpose::memberOrNullByValue($input->getOption(self::OPTION_DATASET_PURPOSE));
        $agentPlatform = AgentPlatform::memberOrNullByValue($input->getOption(self::OPTION_AGENT_PLATFORM));
        $operatingSystem = $input->getOption(self::OPTION_OPERATING_SYSTEM);
        $encryptionKeyStashRecord = $this->getEncryptedKeyStashRecord($input);

        if (!isset($assetKey, $assetUuid, $hostname, $fqdn, $datasetPurpose, $agentPlatform, $operatingSystem)) {
            throw new InvalidArgumentException(
                'Command requires the options: ' . implode(', ', self::OPTIONS)
            );
        }

        $assetMetadata = new AssetMetadata(
            $assetKey,
            $assetUuid,
            $hostname,
            $fqdn,
            $datasetPurpose,
            $agentPlatform,
            $operatingSystem,
            $encryptionKeyStashRecord
        );

        $agent = $this->creationService->createArchivedAgent($assetMetadata);

        $output->writeln($agent->getKeyName());

        return 0;
    }

    private function getEncryptedKeyStashRecord(InputInterface $input): ?EncryptionKeyStashRecord
    {
        $encryptionKeyStashRecord = null;
        $encryptionKeyStashInput = $input->getOption(self::OPTION_ENCRYPTION_KEY_STASH);

        if ($encryptionKeyStashInput) {
            $decoded = \GuzzleHttp\json_decode($encryptionKeyStashInput, true);
            $encryptionKeyStashRecord = EncryptionKeyStashRecord::createFromArray($decoded, true);
        }

        return $encryptionKeyStashRecord;
    }
}
