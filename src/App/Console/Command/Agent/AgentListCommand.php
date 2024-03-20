<?php

namespace Datto\App\Console\Command\Agent;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetType;
use Datto\Config\AgentConfigFactory;
use Datto\Resource\DateTimeService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AgentListCommand extends AbstractAgentCommand
{
    const FIELD_KEY = 'key';
    const FIELD_PAIREDBY = 'pairedby';
    const FIELD_UUID = 'uuid';
    const FIELD_HOSTNAME = 'hostname';
    const FIELD_VERSION = 'version';
    const FIELD_PAUSED = 'paused';
    const FIELD_ARCHIVED = 'archived';
    const FIELD_ENCRYPTED = 'encrypted';
    const FIELD_ZFS = 'zfs';
    const FIELD_CHECKIN = 'checkin';
    const FIELD_ORIGIN = 'origin';

    const DEFAULT_FIELDS = [
        self::FIELD_KEY,
        self::FIELD_PAIREDBY,
        self::FIELD_UUID,
        self::FIELD_HOSTNAME,
        self::FIELD_VERSION,
        self::FIELD_PAUSED,
        self::FIELD_ARCHIVED,
        self::FIELD_ENCRYPTED,
        self::FIELD_ZFS
    ];

    protected static $defaultName = 'agent:list';

    private AgentConfigFactory $agentConfigFactory;

    private DateTimeService $dateTimeService;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->agentConfigFactory = $agentConfigFactory;
        $this->dateTimeService = $dateTimeService;
    }

    protected function configure()
    {
        $this
            ->setDescription('List existing agents in a table')
            ->addArgument('assetKey', InputArgument::OPTIONAL, 'Optional asset key', false)
            ->addOption(
                'fields',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Specify the fields you would like to include (use \'?\' to list them)',
                implode(',', self::DEFAULT_FIELDS)
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fieldsOption = $input->getOption('fields');
        if (empty($fieldsOption) || $fieldsOption === '?') {
            $output->writeln(implode("\n", array_column($this->getFieldResolvers(), 'field')));
            return 0;
        } else {
            $fields = explode(',', $fieldsOption);
            $fields = array_map('trim', $fields);
            $fields = array_map('strtolower', $fields);
        }

        $fieldResolvers = $this->searchFieldResolvers($fields);
        if ($input->getArgument('assetKey')) {
            $agents = [$this->agentService->get($input->getArgument('assetKey'))];
        } else {
            $agents = $this->agentService->getAll();
        }

        $this->renderAgentFields($output, $agents, $fieldResolvers);
        return 0;
    }

    private function renderAgentFields(OutputInterface $output, array $agents, array $fieldResolvers): void
    {
        $table = new Table($output);
        $table->setHeaders(array_column($fieldResolvers, 'header'));

        foreach ($agents as $agent) {
            $row = [];
            foreach ($fieldResolvers as $fieldResolver) {
                $row[] = $fieldResolver['resolver']($agent);
            }
            $table->addRow($row);
        }

        $table->render();
    }

    private function searchFieldResolvers(array $fields): array
    {
        $matches = [];

        foreach ($this->getFieldResolvers() as $fieldResolver) {
            if (in_array($fieldResolver['field'], $fields)) {
                $matches[] = $fieldResolver;
            }
        }

        return $matches;
    }

    private function getFieldResolvers(): array
    {
        return [
            [
                'field' => self::FIELD_KEY,
                'header' => 'Key Name',
                'resolver' => function (Agent $agent) {
                    return $agent->getKeyName();
                }
            ],
            [
                'field' => self::FIELD_PAIREDBY,
                'header' => 'Paired By',
                'resolver' => function (Agent $agent) {
                    if ($agent instanceof AgentlessSystem) {
                        return $agent->getEsxInfo()->getMoRef();
                    } else {
                        return $agent->getPairName();
                    }
                }
            ],
            [
                'field' => self::FIELD_UUID,
                'header' => 'UUID',
                'resolver' => function (Agent $agent) {
                    return $agent->getUuid();
                }
            ],
            [
                'field' => self::FIELD_HOSTNAME,
                'header' => 'Host Name',
                'resolver' => function (Agent $agent) {
                    return $agent->getHostname();
                }
            ],
            [
                'field' => self::FIELD_VERSION,
                'header' => 'Agent Type / Version',
                'resolver' => function (Agent $agent) {
                    return $this->getAgentDescription($agent);
                }
            ],
            [
                'field' => self::FIELD_PAUSED,
                'header' => 'Paused',
                'resolver' => function (Agent $agent) {
                    return $agent->getLocal()->isPaused() ? 'yes' : 'no';
                }
            ],
            [
                'field' => self::FIELD_ARCHIVED,
                'header' => 'Archived',
                'resolver' => function (Agent $agent) {
                    return $agent->getLocal()->isArchived() ? 'yes' : 'no';
                }
            ],
            [
                'field' => self::FIELD_ENCRYPTED,
                'header' => 'Encrypted',
                'resolver' => function (Agent $agent) {
                    return $agent->getEncryption()->isEnabled() ? 'yes' : 'no';
                }
            ],
            [
                'field' => self::FIELD_ZFS,
                'header' => 'ZFS Path',
                'resolver' => function (Agent $agent) {
                    return $agent->getDataset()->getZfsPath();
                }
            ],
            [
                'field' => self::FIELD_CHECKIN,
                'header' => 'Last Checkin',
                'resolver' => function (Agent $agent) {
                    $lastCheckin = $agent->getLocal()->getLastCheckin();
                    if ($lastCheckin) {
                        $lastCheckin = $this->dateTimeService->format('c', $lastCheckin);
                    }
                    return $lastCheckin;
                }
            ],
            [
                'field' => self::FIELD_ORIGIN,
                'header' => 'Origin Device',
                'resolver' => function (Agent $agent) {
                    $origin = $agent->getOriginDevice();

                    return $origin->isReplicated() ? $origin->getDeviceId() : '-';
                }
            ]
        ];
    }

    /**
     * Return a description of the agent, including type and version if applicable.
     * @param Agent $agent
     * @return string
     */
    private function getAgentDescription(Agent $agent)
    {
        $description = $agent->getType();

        if ($agent->isType(AssetType::AGENTLESS_WINDOWS)) {
            $description = "Agentless Windows";
        } elseif ($agent->isType(AssetType::AGENTLESS_LINUX)) {
            $description = "Agentless Linux";
        } elseif ($agent->isType(AssetType::AGENTLESS_GENERIC)) {
            $description = "Agentless Generic";
        } elseif ($agent->isType(AssetType::LINUX_AGENT)) {
            $description = "DLA v. " . $agent->getDriver()->getAgentVersion();
        } elseif ($agent->isType(AssetType::MAC_AGENT)) {
            $description = "DMA v. " . $agent->getDriver()->getAgentVersion();
        } elseif ($agent->isType(AssetType::WINDOWS_AGENT)) {
            $platform = $agent->getPlatform();
            if ($platform === AgentPlatform::DIRECT_TO_CLOUD()) {
                $description = 'DirectToCloud';
            } elseif ($platform === AgentPlatform::DATTO_WINDOWS_AGENT()) {
                $description = "DWA";
            } else {
                $description = "ShadowSnap";
            }
            $description .= ' v. ' .  $agent->getDriver()->getAgentVersion();
        }

        return $description;
    }
}
